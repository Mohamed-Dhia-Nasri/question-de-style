<?php

namespace App\Modules\CRM\Livewire\Creators;

use App\Modules\CRM\Exceptions\PlatformAccountConflict;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Services\CreatorWriter;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\Platform;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Bulk creator import from a CSV (F10). Every row is written through
 * CreatorWriter — never a direct model write — so the same Monitoring
 * auto-enrollment that a single manual creation triggers keeps working for
 * imported creators too.
 *
 * The file is previewed before anything is written: each row gets a verdict
 * (ready / skip with a reason) computed from the parsed upload, and the same
 * verdicts are recomputed at import time so client-sent preview state can
 * never be trusted for the actual writes. Bad or conflicting rows are skipped
 * one at a time; a single conflict never aborts the whole file.
 *
 * The Livewire temporary upload is parsed in place (no permanent storage):
 * this is an in-memory preview-then-write flow, not a stored document.
 */
class CreatorCsvImport extends Component
{
    use WithFileUploads;

    public bool $open = false;

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    /**
     * Parsed preview rows.
     *
     * @var list<array{line:int,name:string,language:string,handles:array<string,string>,verdict:string,reason:?string}>
     */
    public array $rows = [];

    /**
     * Import outcome once import() has run.
     *
     * @var array{created:int,skipped:list<array{line:int,name:string,reason:?string}>}|null
     */
    public ?array $result = null;

    /** A CSV larger than this is split and imported in batches instead. */
    private const MAX_ROWS = 200;

    /** Recognized handle columns → their platform (unknown columns ignored). */
    private const PLATFORM_COLUMNS = [
        'instagram' => Platform::Instagram,
        'tiktok' => Platform::TikTok,
        'youtube' => Platform::YouTube,
    ];

    /** Toolbar button opens the modal via an event (module's first #[On]). */
    #[On('open-csv-import')]
    public function open(): void
    {
        $this->authorize('create', Creator::class);

        $this->reset('upload', 'rows', 'result');
        $this->resetValidation();
        $this->open = true;
    }

    public function close(): void
    {
        $this->reset('open', 'upload', 'rows', 'result');
        $this->resetValidation();
    }

    /** Back to the upload step from the preview, keeping the modal open. */
    public function chooseAnotherFile(): void
    {
        $this->reset('upload', 'rows', 'result');
        $this->resetValidation();
    }

    /** Livewire fires this once the temporary upload finishes. */
    public function updatedUpload(): void
    {
        $this->rows = [];
        $this->result = null;
        $this->resetValidation();

        $this->validate([
            'upload' => ['required', 'file', 'max:1024', 'mimes:csv,txt'],
        ]);

        $this->rows = $this->parse();
    }

    public function import(CreatorWriter $writer, AuditLogger $audit): void
    {
        $this->authorize('create', Creator::class);

        // Recompute verdicts from the stored upload — the preview rows are
        // client state and must never drive the writes.
        $rows = $this->parse();
        $this->rows = $rows;

        if ($rows === []) {
            // A file-level problem (missing name column, too many rows, empty
            // file) left an error on the upload field — stay on the upload
            // step rather than reporting an empty import.
            return;
        }

        $created = 0;

        /** @var list<array{line:int,name:string,reason:?string}> $skipped */
        $skipped = [];

        foreach ($rows as $row) {
            if ($row['verdict'] !== 'ready') {
                $skipped[] = ['line' => $row['line'], 'name' => $row['name'], 'reason' => $row['reason']];

                continue;
            }

            try {
                DB::transaction(function () use ($row, $writer, $audit): void {
                    $creator = $writer->createCreator(
                        $row['name'],
                        $row['language'] !== '' ? $row['language'] : null,
                    );

                    // Identifier-only context — display name is PII (M29).
                    $audit->record('creator.created', $creator, [
                        'source' => 'csv-import',
                    ]);

                    foreach ($row['handles'] as $platformValue => $handle) {
                        $platform = Platform::from($platformValue);

                        $account = $writer->addManualPlatformAccount(
                            $creator,
                            $platform,
                            $handle,
                            surface: CreatorWriter::CSV_IMPORT_SURFACE,
                        );

                        $audit->record('platform_account.added', $account, [
                            'platform' => $platform->value,
                        ]);
                    }
                });

                $created++;
            } catch (PlatformAccountConflict|QueryException) {
                // A handle taken between preview and write (or a race on the
                // per-tenant unique key that escapes as a raw QueryException):
                // roll this row back and keep importing the rest.
                $skipped[] = [
                    'line' => $row['line'],
                    'name' => $row['name'],
                    'reason' => 'This row could not be imported — a handle is already in use.',
                ];
            }
        }

        $this->result = ['created' => $created, 'skipped' => $skipped];

        if ($created > 0) {
            $this->dispatch('notify', type: 'success', message: "Imported {$created} creators.");
            $this->dispatch('creators-imported');
        }
    }

    /**
     * Parse the temporary upload into verdict-tagged rows. File-level problems
     * add an error on the upload field and yield an empty list.
     *
     * @return list<array{line:int,name:string,language:string,handles:array<string,string>,verdict:string,reason:?string}>
     */
    private function parse(): array
    {
        if ($this->upload === null) {
            return [];
        }

        $stream = fopen($this->upload->getRealPath(), 'rb');

        if ($stream === false) {
            $this->addError('upload', 'The file could not be read.');

            return [];
        }

        $header = fgetcsv($stream, 0, ',', '"', '\\');

        if ($header === false || $header === null) {
            fclose($stream);
            $this->addError('upload', 'The file needs a ‘name’ column.');

            return [];
        }

        $header[0] = $this->stripBom((string) ($header[0] ?? ''));

        $columns = [];
        foreach ($header as $index => $cell) {
            $key = strtolower(trim((string) $cell));
            if ($key !== '' && ! array_key_exists($key, $columns)) {
                $columns[$key] = $index;
            }
        }

        if (! array_key_exists('name', $columns)) {
            fclose($stream);
            $this->addError('upload', 'The file needs a ‘name’ column.');

            return [];
        }

        $rows = [];
        $line = 1;

        while (($record = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            $line++;

            if ($record === null || $this->isBlankRecord($record)) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                fclose($stream);
                $this->addError('upload', 'That’s more than 200 rows — split the file and import it in batches.');

                return [];
            }

            $rows[] = [
                'line' => $line,
                'name' => trim((string) ($record[$columns['name']] ?? '')),
                'language' => array_key_exists('language', $columns)
                    ? trim((string) ($record[$columns['language']] ?? ''))
                    : '',
                'handles' => $this->extractHandles($record, $columns),
                'verdict' => 'ready',
                'reason' => null,
            ];
        }

        fclose($stream);

        $this->markIntrinsicSkips($rows);
        $this->markExistingHandleSkips($rows);

        return $rows;
    }

    /**
     * @param  array<int, string|null>  $record
     * @param  array<string, int>  $columns
     * @return array<string, string>
     */
    private function extractHandles(array $record, array $columns): array
    {
        $handles = [];

        foreach (self::PLATFORM_COLUMNS as $column => $platform) {
            if (! array_key_exists($column, $columns)) {
                continue;
            }

            $handle = $this->normalizeHandle((string) ($record[$columns[$column]] ?? ''));

            if ($handle !== '') {
                $handles[$platform->value] = $handle;
            }
        }

        return $handles;
    }

    /**
     * Per-row checks plus within-file duplicate (platform, handle) — the first
     * row to claim a handle wins, later rows carrying it are skipped.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function markIntrinsicSkips(array &$rows): void
    {
        $claimed = [];

        foreach ($rows as &$row) {
            if ($row['name'] === '') {
                $this->skip($row, 'This row has no name.');

                continue;
            }

            if (mb_strlen($row['name']) > 255) {
                $this->skip($row, 'This name is longer than 255 characters.');

                continue;
            }

            if (mb_strlen($row['language']) > 10) {
                $this->skip($row, 'This language code is longer than 10 characters.');

                continue;
            }

            $tooLong = false;
            foreach ($row['handles'] as $handle) {
                if (mb_strlen($handle) > 255) {
                    $tooLong = true;
                    break;
                }
            }

            if ($tooLong) {
                $this->skip($row, 'A handle in this row is longer than 255 characters.');

                continue;
            }

            $duplicate = null;
            foreach ($row['handles'] as $platformValue => $handle) {
                if (isset($claimed[$platformValue.'|'.$handle])) {
                    $duplicate = $handle;
                    break;
                }
            }

            if ($duplicate !== null) {
                $this->skip($row, '@'.$duplicate.' is already listed earlier in this file.');

                continue;
            }

            foreach ($row['handles'] as $platformValue => $handle) {
                $claimed[$platformValue.'|'.$handle] = true;
            }
        }
        unset($row);
    }

    /**
     * One batched existence pass: any (platform, handle) already in this
     * tenant belongs to another creator, so the row is skipped.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function markExistingHandleSkips(array &$rows): void
    {
        $pairs = [];

        foreach ($rows as $row) {
            if ($row['verdict'] !== 'ready') {
                continue;
            }

            foreach ($row['handles'] as $platformValue => $handle) {
                $pairs[$platformValue.'|'.$handle] = [$platformValue, $handle];
            }
        }

        if ($pairs === []) {
            return;
        }

        $existing = [];

        PlatformAccount::query()
            ->where(function (Builder $query) use ($pairs): void {
                foreach ($pairs as [$platformValue, $handle]) {
                    $query->orWhere(fn (Builder $sub) => $sub
                        ->where('platform', $platformValue)
                        ->where('handle', $handle));
                }
            })
            ->get(['platform', 'handle'])
            ->each(function (PlatformAccount $account) use (&$existing): void {
                $existing[$account->platform->value.'|'.$account->handle] = true;
            });

        foreach ($rows as &$row) {
            if ($row['verdict'] !== 'ready') {
                continue;
            }

            foreach ($row['handles'] as $platformValue => $handle) {
                if (isset($existing[$platformValue.'|'.$handle])) {
                    $this->skip($row, '@'.$handle.' already belongs to another creator.');
                    break;
                }
            }
        }
        unset($row);
    }

    /** @param  array<string, mixed>  $row */
    private function skip(array &$row, string $reason): void
    {
        $row['verdict'] = 'skip';
        $row['reason'] = $reason;
    }

    private function normalizeHandle(string $value): string
    {
        $value = trim($value);

        if (str_starts_with($value, '@')) {
            $value = substr($value, 1);
        }

        return trim($value);
    }

    private function stripBom(string $value): string
    {
        return preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
    }

    /** @param  array<int, string|null>  $record */
    private function isBlankRecord(array $record): bool
    {
        foreach ($record as $cell) {
            if (trim((string) ($cell ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    public function render(): View
    {
        return view('livewire.crm.creator-csv-import', [
            'readyCount' => collect($this->rows)->where('verdict', 'ready')->count(),
        ]);
    }
}
