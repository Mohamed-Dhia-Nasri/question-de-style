<?php

namespace App\Platform\Enrichment\VisualMatch\Console;

use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\ReferencePhotoEmbedder;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent reference-photo embedding backfill (spec §6/§14): embeds
 * every (photo, model_version) pair still missing at the CURRENT
 * model_version — photos uploaded while the capability was off, and the
 * whole catalog after a model upgrade. Budget-guarded per photo at
 * priority HIGH, so a backfill can never blow past the global hard caps;
 * a provider error on one photo never aborts the sweep (re-runnable).
 */
class EmbedProductPhotosCommand extends Command
{
    protected $signature = 'qds:embed-product-photos';

    protected $description = 'Embed reference photos missing an embedding at the configured model version';

    public function handle(
        EmbeddingProvider $provider,
        ReferencePhotoEmbedder $embedder,
        TenantContext $tenantContext,
    ): int {
        if (! $provider->isConfigured()) {
            $this->warn('Embedding provider is not configured — nothing embedded.');

            return self::SUCCESS;
        }

        $modelVersion = $provider->modelVersion();
        $embedded = 0;
        $skipped = 0;
        $failed = 0;

        // The console runs tenant-less (TenantScope no-op): ownership is an
        // explicit predicate + per-photo runAs (the PruneKeyframes pattern).
        ProductReferencePhoto::query()
            ->withoutGlobalScopes()
            ->whereNotExists(function ($query) use ($modelVersion): void {
                $query->select(DB::raw(1))
                    ->from('product_photo_embeddings')
                    ->whereColumn('product_photo_embeddings.product_reference_photo_id', 'product_reference_photos.id')
                    ->where('product_photo_embeddings.model_version', $modelVersion);
            })
            ->orderBy('id')
            ->chunkById(100, function ($photos) use ($tenantContext, $embedder, &$embedded, &$skipped, &$failed): void {
                foreach ($photos as $photo) {
                    try {
                        $done = $tenantContext->runAs(
                            (int) $photo->tenant_id,
                            fn (): bool => $embedder->embed($photo),
                        );
                    } catch (ProviderCallException) {
                        // Transient or permanent provider trouble on ONE
                        // photo: count it and keep sweeping — the command
                        // is idempotent and safely re-runnable.
                        $failed++;

                        continue;
                    }

                    $done ? $embedded++ : $skipped++;
                }
            });

        $this->info("Embedded {$embedded}, skipped {$skipped} (budget/read-only), failed {$failed} of the missing (photo, model_version) pairs.");

        return self::SUCCESS;
    }
}
