<?php

namespace App\Platform\Enrichment\Matching\Console;

use App\Platform\Enrichment\Matching\SeededContentLinker;
use Illuminate\Console\Command;

/**
 * REQ-M3-008 — scheduled content-to-campaign matching pass. Self-gating on
 * qds.matching.enabled (same convention as the other pipeline commands):
 * registered unconditionally, skips cleanly until enabled.
 */
class LinkSeededContentCommand extends Command
{
    protected $signature = 'qds:link-seeded-content {--all : Full rescan instead of the qds.matching.lookback_hours window}';

    protected $description = 'Materialize shipment↔content links from SEEDED mentions (REQ-M3-008)';

    public function handle(SeededContentLinker $linker): int
    {
        if (! config('qds.matching.enabled')) {
            $this->info('Content matching is disabled (qds.matching.enabled) — skipping.');

            return self::SUCCESS;
        }

        // Deep-review GAP-2: scheduled passes scan only the recent window;
        // --all forces the historical full rescan (e.g. after enabling
        // matching for the first time or widening the shipment window).
        $since = $this->option('all')
            ? null
            : now()->subHours((int) config('qds.matching.lookback_hours'))->toImmutable();

        $summary = $linker->run($since);

        $this->info(sprintf(
            'Linked %d content item(s) (%d already linked, %d stale reference(s) skipped); '
            .'%d human-confirmed mention(s) without shipment references left for manual linking; '
            .'%d campaign attribution(s) confirmed.',
            $summary->linked,
            $summary->alreadyLinked,
            $summary->staleReferences,
            $summary->withoutReferences,
            $summary->campaignsConfirmed,
        ));

        return self::SUCCESS;
    }
}
