<?php

namespace App\Platform\Enrichment\Recognition;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Http\GoogleSpeechClient;
use App\Platform\Enrichment\Http\GoogleVideoIntelligenceClient;
use App\Platform\Enrichment\Http\GoogleVisionClient;
use App\Platform\Enrichment\Support\ConfidenceScore;
use App\Platform\Enrichment\Support\HumanPrecedence;
use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Observability\AlertService;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\Persistence\PersistenceResult;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\AlertType;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Recognition stage (REQ-M1-008): orchestrates the frozen SRC-google-*
 * providers over a ContentItem/Story's media, normalizes their outputs
 * into ENT-RecognitionDetection rows, and records every provider call in
 * the External API Monitoring sink (ProviderCall + health + alerts —
 * the same telemetry the ingestion providers use).
 *
 * - Images (IMAGE_POST/CAROUSEL, image stories): SRC-google-cloud-vision.
 * - Videos (REEL/VIDEO/SHORT, video stories): SRC-google-video-intelligence,
 *   OPTIONAL per the data-source matrix — only used when configured.
 * - SPOKEN_BRAND (SRC-google-speech-to-text): the AudioExtractor derives a
 *   ≤60s mono FLAC track from the video with local ffmpeg; when ffmpeg is
 *   missing or no audio can be derived, the stage is reported as a skip,
 *   never fabricated.
 *
 * A provider without configured credentials is skipped; its outputs stay
 * unavailable. Detections a human reviewed/corrected are never overwritten
 * by a re-run (DP-004).
 */
class RecognitionService
{
    private const IMAGE_LIMIT_PER_TARGET = 3;

    public function __construct(
        private readonly GoogleVisionClient $vision,
        private readonly GoogleVideoIntelligenceClient $videoIntelligence,
        private readonly GoogleSpeechClient $speech,
        private readonly AudioExtractor $audio,
        private readonly RecognitionNormalizer $normalizer,
        private readonly MediaFetcher $media,
        private readonly ProviderCallRecorder $recorder,
        private readonly AlertService $alerts,
    ) {}

    /**
     * @return array{status: string, created: int, updated: int, skipped: list<string>}
     */
    public function enrich(ContentItem|Story $target, string $correlationId, int $retryCount = 0): array
    {
        $created = 0;
        $updated = 0;
        $skipped = [];

        // No configured provider → nothing to annotate; don't download
        // media for nobody (cost control).
        if (! $this->vision->isConfigured() && ! $this->videoIntelligence->isConfigured() && ! $this->speech->isConfigured()) {
            $skipped[] = 'vision:not-configured';
            $skipped[] = 'video-intelligence:not-configured';
            $skipped[] = 'speech:not-configured';

            return ['status' => 'completed-empty', 'created' => 0, 'updated' => 0, 'skipped' => $skipped];
        }

        [$imageBytes, $videoBytes] = $this->mediaFor($target, $skipped);

        if ($imageBytes !== []) {
            if (! $this->vision->isConfigured()) {
                $skipped[] = 'vision:not-configured';
            } else {
                foreach ($imageBytes as $bytes) {
                    [$c, $u] = $this->annotate(
                        $target,
                        SourceRegistry::GOOGLE_CLOUD_VISION,
                        'vision.annotate',
                        $correlationId,
                        $retryCount,
                        fn (): NormalizedBatch => $this->normalizer->visionBatch($this->vision->annotateImage($bytes)),
                    );

                    $created += $c;
                    $updated += $u;
                }
            }
        }

        if ($videoBytes !== null) {
            if (! $this->videoIntelligence->isConfigured()) {
                // OPTIONAL provider (data-source matrix) — absence is normal.
                $skipped[] = 'video-intelligence:not-configured';
            } else {
                [$c, $u] = $this->annotate(
                    $target,
                    SourceRegistry::GOOGLE_VIDEO_INTELLIGENCE,
                    'video.annotate',
                    $correlationId,
                    $retryCount,
                    fn (): NormalizedBatch => $this->normalizer->videoBatch($this->videoIntelligence->annotateVideo($videoBytes)),
                );

                $created += $c;
                $updated += $u;
            }

            // SPOKEN_BRAND: derive a ≤60s audio track locally, then
            // transcribe. Each gate records its own skip marker so a
            // missing detection is always explainable.
            if (! $this->speech->isConfigured()) {
                $skipped[] = 'speech:not-configured';
            } elseif (! $this->audio->isAvailable()) {
                $skipped[] = 'speech:ffmpeg-unavailable';
            } else {
                $audioBytes = $this->audio->extract($videoBytes);

                if ($audioBytes === null) {
                    // Muted/undecodable media — unavailable, never fabricated.
                    $skipped[] = 'speech:audio-extraction-failed';
                } else {
                    try {
                        [$c, $u] = $this->annotate(
                            $target,
                            SourceRegistry::GOOGLE_SPEECH_TO_TEXT,
                            'speech.recognize',
                            $correlationId,
                            $retryCount,
                            fn (): NormalizedBatch => $this->normalizer->speechBatch($this->speech->recognize($audioBytes)),
                        );

                        $created += $c;
                        $updated += $u;
                    } catch (ProviderCallException $e) {
                        // Speech is the newest, most rate-limit-prone stage and
                        // it now bills per video. A transient speech failure
                        // must NOT fail the whole run and re-bill the already-
                        // succeeded Vision + Video-Intelligence stages on every
                        // retry — the failure is recorded (annotate) and the
                        // run completes with SPOKEN_BRAND simply unavailable.
                        $skipped[] = 'speech:provider-error';
                    }
                }
            }
        }

        return [
            'status' => $created + $updated > 0 ? 'completed' : 'completed-empty',
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  list<string>  $skipped
     * @return array{0: list<string>, 1: string|null} [image byte payloads, video byte payload]
     */
    private function mediaFor(ContentItem|Story $target, array &$skipped): array
    {
        if ($target instanceof Story) {
            $bytes = $this->media->fromStory($target);

            if ($bytes === null) {
                $skipped[] = 'story-media:unavailable';

                return [[], null];
            }

            return $this->looksLikeVideo($target->media_url ?? '') ? [[], $bytes] : [[$bytes], null];
        }

        $urls = array_values(array_filter((array) ($target->media_urls ?? []), 'is_string'));

        if ($urls === []) {
            $skipped[] = 'media:none';

            return [[], null];
        }

        if (in_array($target->content_type, [ContentType::ImagePost, ContentType::Carousel], true)) {
            $images = [];

            foreach (array_slice($urls, 0, self::IMAGE_LIMIT_PER_TARGET) as $url) {
                $bytes = $this->media->fromPublicUrl($url);

                if ($bytes === null) {
                    $skipped[] = 'media:fetch-failed';

                    continue;
                }

                $images[] = $bytes;
            }

            return [$images, null];
        }

        // Video-typed content: single deep pass over the first media asset.
        $bytes = $this->media->fromPublicUrl($urls[0]);

        if ($bytes === null) {
            $skipped[] = 'media:fetch-failed';

            return [[], null];
        }

        return [[], $bytes];
    }

    /**
     * Run one provider call end-to-end with full telemetry.
     *
     * @param  callable(): NormalizedBatch  $call
     * @return array{0: int, 1: int} [created, updated]
     */
    private function annotate(
        ContentItem|Story $target,
        string $source,
        string $operation,
        string $correlationId,
        int $retryCount,
        callable $call,
    ): array {
        $context = $this->recorder->start(
            $source,
            $operation,
            $correlationId,
            null,
            $target instanceof ContentItem ? $target->platform_account_id : $target->platform_account_id,
            $retryCount,
        );

        try {
            $batch = $call();
        } catch (ProviderCallException $e) {
            $this->recorder->recordFailure($context, $e);

            if ($e->category === ErrorCategory::RateLimited) {
                $this->alerts->raise(
                    AlertType::RateLimitRisk,
                    $source,
                    "{$source} answered with a rate-limit response during {$operation}.",
                );
            }

            throw $e;
        }

        $persistStart = microtime(true);

        [$created, $updated] = $this->persist($target, $source, $batch);

        $this->recorder->recordCompletion($context, $batch, new PersistenceResult(
            created: $created,
            duplicates: $updated,
            persistenceMs: (microtime(true) - $persistStart) * 1000,
        ));

        return [$created, $updated];
    }

    /** @return array{0: int, 1: int} */
    private function persist(ContentItem|Story $target, string $source, NormalizedBatch $batch): array
    {
        $created = 0;
        $updated = 0;

        $targetKey = $target instanceof ContentItem ? 'content_item_id' : 'story_id';

        /** @var RecognitionCandidate $candidate */
        foreach ($batch->items as $candidate) {
            // Key the upsert on the IMMUTABLE raw provider label, never on
            // detected_brand (which a human correction mutates). Otherwise a
            // corrected brand would be re-detected as a fresh AI row on the
            // next pass, re-introducing the value the human corrected away
            // (DP-004).
            $identity = [
                $targetKey => $target->id,
                'recognition_type' => $candidate->type,
                // Key on the RAW provider label, not the lexicon-mapped brand,
                // so two distinct labels for one brand stay two detections and
                // the raw label is a stable, immutable identity (M27).
                'provider_label' => $candidate->providerLabel,
            ];

            $detection = RecognitionDetection::query()->firstOrNew($identity);

            if ($detection->exists && ! HumanPrecedence::allowsAiUpdate($detection->assessment)) {
                // Human decision stands (DP-004).
                continue;
            }

            $wasNew = ! $detection->exists;

            $this->applyCandidate($detection, $candidate, $source, $batch->response->sourceVersion);

            try {
                $detection->save();
            } catch (UniqueConstraintViolationException) {
                // A concurrent pass inserted the same detection first (the
                // partial unique index is the backstop). Re-load and honour
                // human precedence on the winning row.
                $detection = RecognitionDetection::query()->where($identity)->firstOrFail();

                if (! HumanPrecedence::allowsAiUpdate($detection->assessment)) {
                    continue;
                }

                $this->applyCandidate($detection, $candidate, $source, $batch->response->sourceVersion);
                $detection->save();
                $wasNew = false;
            }

            $wasNew ? $created++ : $updated++;
        }

        return [$created, $updated];
    }

    private function applyCandidate(
        RecognitionDetection $detection,
        RecognitionCandidate $candidate,
        string $source,
        string $sourceVersion,
    ): void {
        $level = $candidate->score !== null
            ? ConfidenceScore::toLevel($candidate->score)
            : ($candidate->detectedText !== null
                // Deterministic lexicon match inside provider text: the
                // residual uncertainty is the provider's transcription, so
                // MEDIUM, never HIGH without a numeric score.
                ? ConfidenceLevel::Medium
                : ConfidenceLevel::Unknown);

        // detected_brand seeds from the provider label on first write; a
        // later human correction overrides it and is preserved by the
        // precedence guard above (provider_label stays immutable).
        if (! $detection->exists) {
            $detection->detected_brand = $candidate->detectedBrand;
        }

        $detection->fill([
            'detected_text' => $candidate->detectedText,
            'assessment' => new ConfidenceAssessment(
                value: $detection->detected_brand ?? $candidate->detectedBrand,
                confidenceLevel: $level,
                signals: $candidate->signals,
                verificationStatus: VerificationStatus::AiAssessed,
            ),
            'provenance' => new Provenance(
                source: $source,
                fetchedAt: CarbonImmutable::now(),
                sourceVersion: $sourceVersion,
            ),
        ]);
    }

    private function looksLikeVideo(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4', 'mov', 'webm', 'm4v'], true);
    }
}
