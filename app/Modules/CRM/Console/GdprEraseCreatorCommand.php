<?php

namespace App\Modules\CRM\Console;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Services\Gdpr\CreatorEraser;
use Illuminate\Console\Command;

/**
 * GDPR data-subject erasure (DP-005): permanently deletes EVERYTHING the
 * platform holds about one creator, including monitoring history and
 * analytics rows the ordinary CRM delete rightly refuses to touch.
 * Irreversible — requires confirmation (or --force for scripted use).
 */
class GdprEraseCreatorCommand extends Command
{
    protected $signature = 'qds:gdpr-erase-creator {creator : The creator id} {--force : Skip the confirmation prompt}';

    protected $description = 'Permanently erase ALL data about a creator (GDPR data-subject deletion, DP-005)';

    public function handle(CreatorEraser $eraser): int
    {
        $creator = Creator::query()->find((int) $this->argument('creator'));

        if ($creator === null) {
            $this->error('Creator not found.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            "Permanently erase '{$creator->display_name}' (#{$creator->id}) and ALL associated data — monitoring history, analytics, archived media, documents? This cannot be undone.",
        )) {
            $this->info('Aborted — nothing was deleted.');

            return self::SUCCESS;
        }

        $counts = $eraser->erase($creator);

        $this->info("Creator #{$creator->id} erased.");
        $this->table(
            ['Data', 'Deleted'],
            collect($counts)->map(fn ($count, $table) => [$table, $count])->values()->all(),
        );

        return self::SUCCESS;
    }
}
