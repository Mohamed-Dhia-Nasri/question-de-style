<?php

namespace App\Platform\Enrichment\VisualMatch\Frames;

use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\KeyframeEmbedding;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use App\Platform\Ingestion\DTO\ProviderResponse;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\SourceRegistry;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Embeds prepared keyframes through the provider seam, cached per
 * (keyframe, model_version) so retries, multi-candidate scoring and later
 * re-runs are free. One provider call per frame — the model fuses
 * multi-image requests into a SINGLE vector (verified, spec §5), so
 * batching is useless here.
 *
 * Budget doctrine: the frozen embedAll() shape has no denial channel —
 * AiBudgetGuard::allows() runs in VisualProductMatcher BEFORE this class
 * is invoked, and the returned billedCalls feed AiBudgetGuard::record()
 * afterwards. This class only guarantees the billed count is minimal
 * (cache first) and honest (failed calls are never billed).
 *
 * Telemetry lives HERE, not in the provider: only this seam holds the
 * correlationId, and the provider's frozen contract returns bare vectors.
 * A transiently failing frame is telemetered and OMITTED from the result
 * — the run survives and the omission surfaces as reduced coverage,
 * never as a failed enrichment run (spec §5).
 */
final class KeyframeEmbedder
{
    public function __construct(
        private readonly EmbeddingProvider $provider,
        private readonly ProviderCallRecorder $recorder,
    ) {}

    /**
     * @param  list<PreparedFrame>  $frames
     * @return array{embedded: array<int, list<float>>, billedCalls: int, cacheHits: int} keyed by keyframe id
     */
    public function embedAll(array $frames, string $correlationId): array
    {
        $modelVersion = $this->provider->modelVersion();
        $embedded = [];
        $billedCalls = 0;
        $cacheHits = 0;

        foreach ($frames as $frame) {
            $cached = KeyframeEmbedding::query()
                ->where('keyframe_id', $frame->keyframe->id)
                ->where('model_version', $modelVersion)
                ->first();

            if ($cached !== null) {
                $embedded[$frame->keyframe->id] = VectorLiteral::toArray((string) $cached->embedding);
                $cacheHits++;

                continue;
            }

            $context = $this->recorder->start(
                SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
                'embedding.embed',
                $correlationId,
            );
            $startedAt = microtime(true);

            try {
                $vector = $this->provider->embedImage($frame->bytes, $frame->mimeType);
            } catch (ProviderCallException $e) {
                // Transient failure: telemetered, the frame is omitted, the
                // run survives. Not billed — Google bills successful calls.
                $this->recorder->recordFailure($context, $e);

                continue;
            }

            // recordOperation needs a ProviderResponse; the decoded vector
            // is the only payload visible at this seam, so its serialized
            // size is the honest response-size proxy (no fabricated fields).
            $this->recorder->recordOperation($context, new ProviderResponse(
                items: [],
                httpStatus: 200,
                responseBytes: strlen((string) json_encode($vector)),
                requestMs: (microtime(true) - $startedAt) * 1000,
                sourceVersion: $modelVersion,
            ), 1);

            $billedCalls++;
            $embedded[$frame->keyframe->id] = $this->persist($frame->keyframe, $modelVersion, $vector);
        }

        return ['embedded' => $embedded, 'billedCalls' => $billedCalls, 'cacheHits' => $cacheHits];
    }

    /**
     * @param  list<float>  $vector
     * @return list<float> the vector now cached for this (keyframe, model_version)
     */
    private function persist(Keyframe $keyframe, string $modelVersion, array $vector): array
    {
        $row = new KeyframeEmbedding([
            'keyframe_id' => $keyframe->id,
            'model_version' => $modelVersion,
            'embedding' => VectorLiteral::fromArray($vector),
        ]);

        try {
            // SAVEPOINT when already inside a transaction, so a collision
            // never poisons a caller's wider unit of work (house pattern,
            // YouTubeTranscriptEnricher).
            KeyframeEmbedding::query()->withSavepointIfNeeded(fn () => $row->save());

            return $vector;
        } catch (UniqueConstraintViolationException) {
            // A concurrent embed of this frame won the (keyframe_id,
            // model_version) unique key. Same input + same model ⇒ the
            // winner's vector IS ours — reload it, never clobber. Our call
            // still happened, so it stays billed and telemetered.
            $winner = KeyframeEmbedding::query()
                ->where('keyframe_id', $keyframe->id)
                ->where('model_version', $modelVersion)
                ->first();

            return $winner === null ? $vector : VectorLiteral::toArray((string) $winner->embedding);
        }
    }
}
