<?php

namespace App\Platform\Enrichment\VisualMatch\Jobs;

use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Platform\Enrichment\VisualMatch\ReferencePhotoEmbedder;
use App\Platform\Ingestion\Jobs\Concerns\IngestionJobBehaviour;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queued embedding of ONE reference photo (spec §6): dispatched on upload
 * when the capability is on and the provider is configured. ShouldBeUnique
 * on (photo, model_version) keeps duplicate uploads/backfills from racing
 * the same billed call; the embedder is idempotent besides. Carries only
 * scalar identifiers (queues doctrine); transient provider failures retry
 * with backoff, permanent ones fail fast (IngestionJobBehaviour).
 */
class EmbedProductPhotoJob implements ShouldBeUnique, ShouldQueue
{
    use IngestionJobBehaviour;
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    /** Photo embeds run outside monitoring cycles. */
    public readonly ?int $cycleId;

    public readonly string $correlationId;

    public function __construct(
        public readonly int $photoId,
        ?string $correlationId = null,
    ) {
        $this->cycleId = null;
        $this->correlationId = $correlationId ?? (string) Str::uuid();
        $this->onQueue('enrichment');
    }

    public function uniqueId(): string
    {
        return 'photo:'.$this->photoId.':'.config('qds.enrichment.visual_match.model_version');
    }

    public function uniqueFor(): int
    {
        return $this->timeout + 60;
    }

    public function handle(ReferencePhotoEmbedder $embedder): void
    {
        $this->attachLogContext();

        // Worker context is tenant-less (TenantScope no-op) — the
        // EnrichContentItemJob precedent: plain find, then runAs the row's
        // tenant so every write stamps the right owner.
        $photo = ProductReferencePhoto::query()->find($this->photoId);

        if ($photo === null) {
            return; // deleted between dispatch and run — nothing to embed
        }

        try {
            app(TenantContext::class)->runAs(
                (int) $photo->tenant_id,
                fn (): bool => $embedder->embed($photo),
            );
        } catch (Throwable $e) {
            $this->handleProviderFailure($e);
        }
    }
}
