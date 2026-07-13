<?php

namespace App\Platform\Ingestion\Persistence;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Ingestion\DTO\StoryData;
use Carbon\CarbonImmutable;

/**
 * Idempotent persistence for ENT-Story (REQ-M1-004, AC-M1-005). Stories are
 * always ENT-Story, never ContentItems (rule F8).
 *
 * - Dedup key: (platform, external_id) — re-polling a live story updates
 *   its mutable public metrics, never duplicates it.
 * - `media_url` holds the PRIVATE storage path once ArchiveStoryMediaJob
 *   has downloaded the media; this persister never writes the provider's
 *   CDN URL into it. New stories needing archival are returned so the
 *   caller can dispatch archival jobs.
 * - Fields listed in `human_overrides` are never clobbered (requirement 17).
 */
class StoryPersister
{
    /**
     * @param  list<StoryData>  $items
     * @return array{result: PersistenceResult, toArchive: list<array{story: Story, mediaSourceUrl: string}>}
     */
    public function persist(PlatformAccount $account, array $items): array
    {
        $startedAt = microtime(true);

        $created = 0;
        $duplicates = 0;
        $skipped = 0;
        $toArchive = [];

        foreach ($items as $item) {
            if ($item->platform !== $account->platform) {
                $skipped++;

                continue;
            }

            // ADR-0019: the natural key is (tenant_id, platform, external_id)
            // — the lookup is EXPLICITLY scoped to the account's tenant
            // (explicit beats ambient in pipeline code), never relying on the
            // TenantScope alone.
            $existing = Story::query()
                ->where('tenant_id', $account->tenant_id)
                ->where('platform', $item->platform->value)
                ->where('external_id', $item->externalId)
                ->first();

            if ($existing === null) {
                $story = new Story([
                    'platform_account_id' => $account->id,
                    'platform' => $item->platform,
                    'external_id' => $item->externalId,
                    'media_url' => null,
                    'captured_at' => CarbonImmutable::now(),
                    'expires_at' => $item->expiresAt,
                    'public_metrics' => $item->publicMetrics,
                    'provenance' => $item->provenance,
                ]);
                // Explicit ownership from the parent account row (ADR-0019).
                $story->tenant_id = $account->tenant_id;
                $story->save();

                $created++;

                if ($item->mediaSourceUrl !== null) {
                    $toArchive[] = ['story' => $story, 'mediaSourceUrl' => $item->mediaSourceUrl];
                }

                continue;
            }

            $updates = [
                'expires_at' => $item->expiresAt ?? $existing->expires_at,
                'public_metrics' => $item->publicMetrics,
                'provenance' => $item->provenance,
            ];

            foreach ($this->overriddenFields($existing) as $field) {
                unset($updates[$field]);
            }

            $existing->update($updates);

            $duplicates++;

            // Re-attempt archival if it never succeeded and the media is
            // still retrievable (story not yet expired) — but never after
            // retention pruning removed it deliberately (DP-005).
            if ($existing->media_url === null && $existing->media_pruned_at === null && $item->mediaSourceUrl !== null) {
                $toArchive[] = ['story' => $existing, 'mediaSourceUrl' => $item->mediaSourceUrl];
            }
        }

        return [
            'result' => new PersistenceResult(
                created: $created,
                duplicates: $duplicates,
                skipped: $skipped,
                persistenceMs: (microtime(true) - $startedAt) * 1000,
            ),
            'toArchive' => $toArchive,
        ];
    }

    /** @return list<string> */
    private function overriddenFields(Story $story): array
    {
        $overrides = $story->human_overrides;

        return is_array($overrides) ? array_values(array_filter($overrides, 'is_string')) : [];
    }
}
