<?php

namespace App\Platform\Enrichment\VisualMatch;

use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Platform\AiBudget\AiBudgetGuard;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use App\Platform\Ingestion\DTO\ProviderResponse;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\SourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Embeds ONE product reference photo through the container-bound
 * EmbeddingProvider (spec §6). Idempotent per (photo, model_version): an
 * existing embedding row short-circuits without a provider call, so the
 * upload job, the backfill command, and model upgrades can all call this
 * blindly. Skips (unconfigured / read-only / budget / missing blob)
 * return false and write NOTHING — never a fabricated vector.
 *
 * Priority is HIGH by doctrine (§10): photo embeds are user-triggered
 * catalog work — they ignore tenant soft caps and stop only at the
 * global hard caps or read-only mode.
 *
 * Telemetry lives HERE, not in the provider (GeminiMultimodalEmbeddingProvider's
 * own docblock names this class as one of its two callers): only this seam
 * holds the observability context, and the provider's frozen `embedImage`
 * contract returns a bare vector. `embed()` carries no correlationId
 * parameter (frozen signature the job/command/Task 10 rely on verbatim), so
 * each call opens its own — a photo embed is a standalone unit of work, not
 * a step chained to sibling calls under one enrichment-run correlation. A
 * provider failure is telemetered then RE-THROWN — the job and the backfill
 * command each decide retry/fail-fast/count-and-continue themselves.
 */
final class ReferencePhotoEmbedder
{
    public function __construct(
        private readonly EmbeddingProvider $provider,
        private readonly AiBudgetGuard $budget,
        private readonly ProviderCallRecorder $recorder,
    ) {}

    public function embed(ProductReferencePhoto $photo): bool
    {
        $modelVersion = $this->provider->modelVersion();

        $exists = ProductPhotoEmbedding::query()
            ->withoutGlobalScopes()
            ->where('product_reference_photo_id', $photo->id)
            ->where('model_version', $modelVersion)
            ->exists();

        if ($exists) {
            return true; // already embedded — idempotency, not a skip
        }

        if (! $this->provider->isConfigured()) {
            return false;
        }

        $decision = $this->budget->allows('embedding', (int) $photo->tenant_id, 1, Priority::High);

        if (! $decision->allowed) {
            return false;
        }

        $bytes = Storage::disk((string) $photo->storage_disk)->get((string) $photo->storage_path);

        if (! is_string($bytes) || $bytes === '') {
            return false; // blob missing — unavailable ≠ false, nothing written
        }

        $context = $this->recorder->start(
            SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
            'embedding.embed',
            (string) Str::uuid(),
        );
        $startedAt = microtime(true);

        try {
            $vector = $this->provider->embedImage($bytes, $this->mimeType($photo));
        } catch (ProviderCallException $e) {
            // Telemetered, then re-thrown: the job (retry/fail-fast) and the
            // backfill command (count-and-continue) each own that decision.
            $this->recorder->recordFailure($context, $e);

            throw $e;
        }

        // recordOperation needs a ProviderResponse; the decoded vector is the
        // only payload visible at this seam, so its serialized size is the
        // honest response-size proxy (no fabricated fields) — same pattern
        // as KeyframeEmbedder, the other caller of this provider.
        $this->recorder->recordOperation($context, new ProviderResponse(
            items: [],
            httpStatus: 200,
            responseBytes: strlen((string) json_encode($vector)),
            requestMs: (microtime(true) - $startedAt) * 1000,
            sourceVersion: $modelVersion,
        ), 1);

        try {
            (new ProductPhotoEmbedding)->forceFill([
                'tenant_id' => $photo->tenant_id,
                'product_reference_photo_id' => $photo->id,
                'model_version' => $modelVersion,
                'embedding' => VectorLiteral::fromArray($vector),
                'created_at' => CarbonImmutable::now(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent embed won the (photo, model_version) insert
            // race. The row exists — the goal is met; our billed call
            // still gets counted below (it really happened).
        }

        $this->budget->record('embedding', (int) $photo->tenant_id, 1);

        return true;
    }

    /**
     * Uploads are restricted to jpg/jpeg/png/webp (spec §4.1), so the
     * stored extension is a trustworthy mime source; jpeg is the default.
     */
    private function mimeType(ProductReferencePhoto $photo): string
    {
        $extension = strtolower(pathinfo((string) $photo->storage_path, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
