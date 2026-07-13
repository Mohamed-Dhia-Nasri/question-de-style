<?php

namespace App\Platform\Ingestion\Jobs;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Ingestion\DTO\ContentData;
use App\Platform\Ingestion\Jobs\Concerns\IngestionJobBehaviour;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\Observability\ProviderCircuitBreaker;
use App\Platform\Ingestion\Persistence\ContentItemPersister;
use App\Platform\Ingestion\Persistence\PersistenceResult;
use App\Platform\Ingestion\Providers\Instagram\InstagramDirectUrlAdapter;
use App\Shared\Enums\Platform;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily metric refresh for CAMPAIGN-LINKED content that has aged out of the
 * roster's refresh window (cost plan follow-up to rec 1): posts matched to
 * a producing seeding campaign (shipment_resulting_content, REQ-M3-008)
 * keep their EMV/CPE metrics live via SRC-apify-instagram-scraper direct
 * post URLs — paying only for the specific posts that still matter instead
 * of widening the whole roster's window.
 *
 * Scope: Instagram only in v1 (direct-URL refresh is verified for the
 * general Instagram actor; the TikTok actor has no per-URL input — TikTok
 * campaign content inside the refresh window is covered by normal cycles).
 * Eligible campaigns: ACTIVE/SHIPPING, plus COMPLETED ones still inside
 * the settle window (metrics keep accruing shortly after a campaign ends;
 * updated_at is the best available proxy for the completion time).
 */
class RefreshCampaignContentJob implements ShouldBeUnique, ShouldQueue
{
    use IngestionJobBehaviour;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public ?string $source = null;

    /** Satisfies IngestionJobBehaviour's bookkeeping — no cycle row here. */
    public ?int $cycleId = null;

    public function __construct(public readonly string $correlationId)
    {
        $this->onQueue('ingestion');
    }

    public function uniqueId(): string
    {
        return 'qds-campaign-refresh';
    }

    public function handle(
        InstagramDirectUrlAdapter $adapter,
        ProviderCallRecorder $recorder,
        ContentItemPersister $persister,
        ProviderCircuitBreaker $breaker,
    ): void {
        $this->attachLogContext();
        $this->source = $adapter->source();

        if ($breaker->shouldSkip($adapter->source())) {
            return;
        }

        $items = $this->eligibleItems();

        if ($items->isEmpty()) {
            return;
        }

        $accounts = PlatformAccount::query()
            ->whereIn('id', $items->pluck('platform_account_id')->unique()->all())
            ->get()
            ->keyBy('id');

        // ADR-0019: natural keys are tenant-scoped, so the SAME public post
        // may legitimately exist as one row per tenant — group (never key)
        // by permalink so no tenant's row is silently dropped, and request
        // each URL once regardless of how many tenants track it.
        $itemsByPermalink = $items->groupBy(
            fn (ContentItem $item): string => rtrim((string) $item->permalink, '/'),
        );

        $batchSize = max(1, (int) config('qds.ingestion.campaign_refresh.batch_size'));

        $urlBatch = $items->pluck('permalink')
            ->map(fn ($permalink): string => rtrim((string) $permalink, '/'))
            ->unique()
            ->values()
            ->all();

        foreach (array_chunk($urlBatch, $batchSize) as $urls) {
            $context = $recorder->start(
                source: $adapter->source(),
                operation: 'content.refresh',
                correlationId: $this->correlationId,
                jobId: $this->job?->uuid(),
                platformAccountId: null,
                retryCount: max(0, $this->attempts() - 1),
            );

            try {
                $batch = $adapter->fetchByUrls($urls);
            } catch (Throwable $e) {
                $recorder->recordFailure($context, $e);

                // Chunks are independent; a failed chunk never blocks the
                // rest, and tries=1 keeps a bad day from re-billing (the
                // next daily run covers the gap).
                continue;
            }

            $result = $this->persistRefreshed($batch->items, $itemsByPermalink, $accounts, $persister);

            $recorder->recordCompletion($context, $batch, $result);
        }
    }

    /**
     * Instagram items with a stored permalink, older than the refresh
     * window, linked to a producing (or recently settled) seeding campaign.
     *
     * @return Collection<int, ContentItem>
     */
    private function eligibleItems(): Collection
    {
        $windowDays = (int) config('qds.ingestion.refresh_window_days');
        // With windowing disabled, normal cycles still only cover the
        // newest N items — anything older still needs this pass.
        $cutoffDays = $windowDays > 0 ? $windowDays : 14;
        $settleDays = max(0, (int) config('qds.ingestion.campaign_refresh.settle_days'));
        $maxUrls = max(1, (int) config('qds.ingestion.campaign_refresh.max_urls_per_run'));

        return ContentItem::query()
            ->where('platform', Platform::Instagram->value)
            ->whereNotNull('permalink')
            ->where('published_at', '<', CarbonImmutable::now()->subDays($cutoffDays))
            ->whereExists(function ($query) use ($settleDays): void {
                $query->select(DB::raw(1))
                    ->from('shipment_resulting_content')
                    ->join('shipments', 'shipments.id', '=', 'shipment_resulting_content.shipment_id')
                    ->join('seeding_campaigns', 'seeding_campaigns.id', '=', 'shipments.seeding_campaign_id')
                    ->whereColumn('shipment_resulting_content.content_item_id', 'content_items.id')
                    ->where(function ($q) use ($settleDays): void {
                        $q->whereIn('seeding_campaigns.status', [
                            SeedingCampaignStatus::Active->value,
                            SeedingCampaignStatus::Shipping->value,
                        ])->orWhere(function ($settled) use ($settleDays): void {
                            $settled->where('seeding_campaigns.status', SeedingCampaignStatus::Completed->value)
                                ->where('seeding_campaigns.updated_at', '>=', CarbonImmutable::now()->subDays($settleDays));
                        });
                    });
            })
            // Oldest-refreshed first so every linked item cycles through
            // even when the roster exceeds max_urls_per_run.
            ->orderBy('updated_at')
            ->limit($maxUrls)
            ->get();
    }

    /**
     * Map refreshed records back to their accounts (via the permalink they
     * were requested by, falling back to external id) and upsert. Each
     * fetched record fans out to EVERY matching item row — under ADR-0019
     * the same public post can be tracked by several tenants at once.
     *
     * @param  list<object>  $fetched
     * @param  Collection<string, Collection<int, ContentItem>>  $itemsByPermalink
     * @param  Collection<int, PlatformAccount>  $accounts
     */
    private function persistRefreshed(
        array $fetched,
        Collection $itemsByPermalink,
        Collection $accounts,
        ContentItemPersister $persister,
    ): PersistenceResult {
        $byExternalId = $itemsByPermalink->flatten(1)->groupBy(
            fn (ContentItem $item): string => (string) $item->external_id,
        );

        $perAccount = [];
        $orphans = 0;

        foreach ($fetched as $data) {
            if (! $data instanceof ContentData) {
                continue;
            }

            /** @var Collection<int, ContentItem>|null $knownItems */
            $knownItems = ($data->permalink !== null
                    ? $itemsByPermalink->get(rtrim($data->permalink, '/'))
                    : null)
                ?? $byExternalId->get($data->externalId);

            if ($knownItems === null || $knownItems->isEmpty()) {
                $orphans++;

                continue;
            }

            foreach ($knownItems as $known) {
                $perAccount[$known->platform_account_id][] = $data;
            }
        }

        if ($orphans > 0) {
            Log::warning('qds.ingestion: campaign refresh returned records matching no requested item.', [
                'orphans' => $orphans,
            ]);
        }

        $created = 0;
        $duplicates = 0;
        $skipped = $orphans;
        $persistenceMs = 0.0;

        foreach ($perAccount as $accountId => $records) {
            $account = $accounts->get($accountId);

            if ($account === null) {
                $skipped += count($records);

                continue;
            }

            // ADR-0019: this job refreshes campaign content across ALL
            // tenants in one run — establish the owning account's tenant
            // per unit of work (runAs restores the previous context) so the
            // persister's writes stamp the right tenant.
            $result = app(TenantContext::class)->runAs(
                $account->tenant_id,
                fn (): PersistenceResult => $persister->persist($account, $records),
            );

            $created += $result->created;
            $duplicates += $result->duplicates;
            $skipped += $result->skipped;
            $persistenceMs += $result->persistenceMs;
        }

        return new PersistenceResult(
            created: $created,
            duplicates: $duplicates,
            skipped: $skipped,
            persistenceMs: $persistenceMs,
        );
    }
}
