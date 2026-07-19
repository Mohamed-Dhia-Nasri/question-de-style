<?php

namespace App\Platform\Enrichment\VisualMatch;

use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Platform\AiBudget\AiBudgetGuard;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;

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
 * global hard caps or read-only mode. ProviderCallRecorder telemetry and
 * AiPayloadGuard live INSIDE the provider implementation, so every path
 * into a billed call shares them by construction.
 */
final class ReferencePhotoEmbedder
{
    public function __construct(
        private readonly EmbeddingProvider $provider,
        private readonly AiBudgetGuard $budget,
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

        $vector = $this->provider->embedImage($bytes, $this->mimeType($photo));

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
