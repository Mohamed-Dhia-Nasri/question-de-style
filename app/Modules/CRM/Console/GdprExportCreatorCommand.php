<?php

namespace App\Modules\CRM\Console;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Services\Gdpr\CreatorDataExporter;
use App\Shared\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * GDPR data-subject access/portability export (DP-005): writes one JSON
 * document with everything the platform holds about a creator to the
 * PRIVATE exports disk. The operator delivers it to the data subject
 * out-of-band; leftover files are pruned by qds:gdpr-enforce-retention.
 */
class GdprExportCreatorCommand extends Command
{
    protected $signature = 'qds:gdpr-export-creator {creator : The creator id}';

    protected $description = 'Export all stored data about a creator as JSON (GDPR data-subject access, DP-005)';

    public function handle(CreatorDataExporter $exporter, AuditLogger $audit): int
    {
        $creator = Creator::query()->find((int) $this->argument('creator'));

        if ($creator === null) {
            $this->error('Creator not found.');

            return self::FAILURE;
        }

        $data = $exporter->export($creator);

        $disk = (string) config('qds.exports.disk');
        // ADR-0019: per-tenant prefix, tenant taken from the creator ROW
        // (console runs have no ambient context). The retention sweep
        // (qds:gdpr-enforce-retention) covers tenants/*/gdpr too.
        $path = sprintf(
            'tenants/%d/gdpr/creator-%d-%s.json',
            $creator->tenant_id,
            $creator->id,
            CarbonImmutable::now()->format('Ymd_His'),
        );

        try {
            $json = json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $e) {
            // Never write a 0-byte dossier and never record a falsified
            // success audit event — the operator must not hand a data subject
            // an empty file believing the export completed.
            $this->error('GDPR export failed to encode: '.$e->getMessage());

            return self::FAILURE;
        }

        Storage::disk($disk)->put($path, $json);

        $audit->record('creator.gdpr_exported', $creator, ['path' => $path]);

        $this->info("GDPR export written to '{$path}' on the '{$disk}' disk (private).");

        return self::SUCCESS;
    }
}
