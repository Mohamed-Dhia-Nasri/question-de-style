<?php

namespace App\Platform\Ingestion\Persistence;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Ingestion\DTO\ContentData;

/**
 * Idempotent persistence for ENT-ContentItem (write path of Module 1's
 * ingestion pipeline per the ownership matrix).
 *
 * - Dedup key: the canonical (platform, external_id) unique pair — a
 *   re-seen record NEVER creates a duplicate (requirement 8, AC-M1-001).
 * - Mutable public metrics (and caption/media edits) are refreshed in
 *   place on the existing row (requirement 9), with fresh Provenance.
 * - Fields listed in `human_overrides` are preserved verbatim — later
 *   ingestion runs never clobber a human correction (requirement 17,
 *   DP-004).
 */
class ContentItemPersister
{
    /**
     * @param  list<ContentData>  $items
     */
    public function persist(PlatformAccount $account, array $items): PersistenceResult
    {
        $startedAt = microtime(true);

        $created = 0;
        $duplicates = 0;
        $skipped = 0;

        foreach ($items as $item) {
            if ($item->platform !== $account->platform) {
                $skipped++;

                continue;
            }

            // ADR-0019: the natural key is (tenant_id, platform, external_id)
            // — the lookup is EXPLICITLY scoped to the account's tenant
            // (explicit beats ambient in pipeline code), never relying on the
            // TenantScope alone.
            $existing = ContentItem::query()
                ->where('tenant_id', $account->tenant_id)
                ->where('platform', $item->platform->value)
                ->where('external_id', $item->externalId)
                ->first();

            if ($existing === null) {
                $contentItem = new ContentItem([
                    'platform_account_id' => $account->id,
                    'platform' => $item->platform,
                    'content_type' => $item->contentType,
                    'external_id' => $item->externalId,
                    'caption' => $item->caption,
                    'media_urls' => $item->mediaUrls,
                    'permalink' => $item->permalink,
                    'published_at' => $item->publishedAt,
                    'public_metrics' => $item->publicMetrics,
                    'provenance' => $item->provenance,
                ]);
                // Explicit ownership from the parent account row (ADR-0019).
                $contentItem->tenant_id = $account->tenant_id;
                $contentItem->save();

                $created++;

                continue;
            }

            $updates = [
                'caption' => $item->caption,
                'media_urls' => $item->mediaUrls,
                // Keep the known permalink when a provider omits it (the
                // direct-URL refresh depends on it staying populated).
                'permalink' => $item->permalink ?? $existing->permalink,
                'published_at' => $item->publishedAt ?? $existing->published_at,
                'public_metrics' => $item->publicMetrics,
                'provenance' => $item->provenance,
            ];

            foreach ($this->overriddenFields($existing) as $field) {
                unset($updates[$field]);
            }

            $existing->update($updates);

            $duplicates++;
        }

        return new PersistenceResult(
            created: $created,
            duplicates: $duplicates,
            skipped: $skipped,
            persistenceMs: (microtime(true) - $startedAt) * 1000,
        );
    }

    /** @return list<string> */
    private function overriddenFields(ContentItem $item): array
    {
        $overrides = $item->human_overrides;

        return is_array($overrides) ? array_values(array_filter($overrides, 'is_string')) : [];
    }
}
