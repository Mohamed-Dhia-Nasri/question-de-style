<?php

namespace App\Platform\Enrichment\Recognition;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\Story;
use App\Platform\AiBudget\AiBudgetGuard;
use App\Platform\Enrichment\Http\GoogleSpeechClient;
use App\Platform\Enrichment\Http\GoogleSpeechV2Client;
use App\Platform\Enrichment\Http\GoogleVideoIntelligenceClient;
use App\Platform\Enrichment\Http\GoogleVisionClient;
use App\Platform\Enrichment\Http\SpeechV2Result;
use App\Platform\Enrichment\Media\LocalMediaAsset;
use App\Platform\Enrichment\Media\MediaWorkspace;
use App\Platform\Enrichment\Media\MediaWorkspaceFactory;
use App\Platform\Enrichment\Speech\ChunkTranscript;
use App\Platform\Enrichment\Speech\Jobs\TranscribeExtendedAudioJob;
use App\Platform\Enrichment\Speech\SpeechAudioChunkWriter;
use App\Platform\Enrichment\Speech\SpeechPhraseHints;
use App\Platform\Enrichment\Speech\SpeechTranscriptWriter;
use App\Platform\Enrichment\Support\ConfidenceScore;
use App\Platform\Enrichment\Support\HumanPrecedence;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateScope;
use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Observability\AlertService;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\Observability\ProviderCircuitBreaker;
use App\Platform\Ingestion\Persistence\PersistenceResult;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\AlertType;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\Platform;
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
    private const SPEECH_CAPABILITY = 'speech_transcription';

    public function __construct(
        private readonly GoogleVisionClient $vision,
        private readonly GoogleVideoIntelligenceClient $videoIntelligence,
        private readonly GoogleSpeechClient $speech,
        private readonly AudioExtractor $audio,
        private readonly RecognitionNormalizer $normalizer,
        private readonly MediaWorkspaceFactory $workspaces,
        private readonly ProviderCallRecorder $recorder,
        private readonly AlertService $alerts,
        private readonly GoogleSpeechV2Client $speechV2,
        private readonly AudioChunker $chunker,
        private readonly SpeechAudioChunkWriter $chunkWriter,
        private readonly SpeechTranscriptWriter $transcripts,
        private readonly SpeechPhraseHints $phrases,
        private readonly CandidateScope $candidateScope,
        private readonly AiBudgetGuard $budget,
        private readonly ProviderCircuitBreaker $breaker,
    ) {}

    /**
     * @return array{status: string, created: int, updated: int, skipped: list<string>}
     */
    public function enrich(ContentItem|Story $target, string $correlationId, int $retryCount = 0, ?MediaWorkspace $workspace = null): array
    {
        $created = 0;
        $updated = 0;
        $skipped = [];

        // YouTube SPOKEN_BRAND rides the transcript the pipeline's transcript
        // stage persisted (ADR-0028) — consume-only: recognition never calls
        // the actor, and no ProviderCall is recorded for this local mining.
        if ($target instanceof ContentItem && $target->platform === Platform::YouTube) {
            $transcript = ContentTranscript::query()
                ->where('content_item_id', $target->id)
                ->where('status', ContentTranscript::STATUS_AVAILABLE)
                ->latest('id')
                ->first();

            if ($transcript === null) {
                $skipped[] = 'youtube-transcript:unavailable';
            } else {
                [$c, $u] = $this->persist($target, SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, $this->normalizer->transcriptBatch((string) $transcript->text));
                $created += $c;
                $updated += $u;
            }
        }

        // No configured provider → nothing to annotate; don't download
        // media for nobody (cost control). With the v2 switch on, the v2
        // client is the speech provider this gate consults (spec §9).
        $speechConfigured = $this->speechV2Enabled()
            ? $this->speechV2->isConfigured()
            : $this->speech->isConfigured();

        if (! $this->vision->isConfigured() && ! $this->videoIntelligence->isConfigured() && ! $speechConfigured) {
            $skipped[] = 'vision:not-configured';
            $skipped[] = 'video-intelligence:not-configured';
            $skipped[] = $this->speechV2Enabled() ? 'speech:v2-not-configured' : 'speech:not-configured';

            return [
                'status' => $created + $updated > 0 ? 'completed' : 'completed-empty',
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ];
        }

        $ownWorkspace = $workspace === null;
        $workspace ??= $this->workspaces->forTarget($target);

        try {
            $images = $workspace->images();
            $video = $workspace->video();

            foreach ($workspace->markers() as $marker) {
                $skipped[] = $marker;
            }

            $inlineMax = (int) config('qds.enrichment.recognition.inline_max_bytes');

            if ($images !== []) {
                if (! $this->vision->isConfigured()) {
                    $skipped[] = 'vision:not-configured';
                } else {
                    foreach ($images as $image) {
                        if ($image->byteSize > $inlineMax) {
                            // Only reachable for a video-branch-routed image
                            // (acquired under the download cap): keyframes
                            // keep it, the inline Vision send does not.
                            $skipped[] = 'recognition:image-skipped-too-large';

                            continue;
                        }

                        [$c, $u] = $this->annotate(
                            $target,
                            SourceRegistry::GOOGLE_CLOUD_VISION,
                            'vision.annotate',
                            $correlationId,
                            $retryCount,
                            fn (): NormalizedBatch => $this->normalizer->visionBatch($this->vision->annotateImage($image->bytes())),
                        );

                        $created += $c;
                        $updated += $u;
                    }
                }
            }

            if ($video !== null) {
                if (! $this->videoIntelligence->isConfigured()) {
                    // OPTIONAL provider (data-source matrix) — absence is normal.
                    $skipped[] = 'video-intelligence:not-configured';
                } elseif ($video->byteSize > $inlineMax) {
                    // The whole-video inline pass is skipped — NOT the media:
                    // keyframes still cover this video (sub-project B split).
                    $skipped[] = 'recognition:whole-video-skipped-too-large';
                } else {
                    [$c, $u] = $this->annotate(
                        $target,
                        SourceRegistry::GOOGLE_VIDEO_INTELLIGENCE,
                        'video.annotate',
                        $correlationId,
                        $retryCount,
                        fn (): NormalizedBatch => $this->normalizer->videoBatch($this->videoIntelligence->annotateVideo($video->bytes())),
                    );

                    $created += $c;
                    $updated += $u;
                }

                // SPOKEN_BRAND: derive a ≤60s audio track locally, then
                // transcribe. Each gate records its own skip marker so a
                // missing detection is always explainable. Runs for ANY
                // downloaded video size — the cap above is inline-only.
                // The v2 sub-path (sub-project D, spec §9) is a full
                // routing swap; OFF keeps this v1 arm byte-identical.
                if ($this->speechV2Enabled()) {
                    [$c, $u, $speechMarkers] = $this->speechV2Pass($target, $video, $correlationId, $retryCount);
                    $created += $c;
                    $updated += $u;

                    foreach ($speechMarkers as $marker) {
                        $skipped[] = $marker;
                    }
                } elseif (! $this->speech->isConfigured()) {
                    $skipped[] = 'speech:not-configured';
                } elseif (! $this->audio->isAvailable()) {
                    $skipped[] = 'speech:ffmpeg-unavailable';
                } else {
                    $audioBytes = $this->audio->extractFromFile($video->tempPath);

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
                            // A transient speech failure must NOT fail the whole
                            // run and re-bill the already-succeeded stages.
                            $skipped[] = 'speech:provider-error';
                        }
                    }
                }
            }
        } finally {
            if ($ownWorkspace) {
                $workspace->close();
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

    /**
     * Public since sub-project D: TranscribeExtendedAudioJob persists its
     * per-chunk SPOKEN_BRAND batches through this exact upsert (identity,
     * DP-004 precedence, unique-violation recovery) instead of duplicating
     * it — the same augment-not-replace shape as the backfill precedent.
     *
     * @return array{0: int, 1: int}
     */
    public function persist(ContentItem|Story $target, string $source, NormalizedBatch $batch): array
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

    private function speechV2Enabled(): bool
    {
        return (bool) config('qds.enrichment.speech.v2_enabled');
    }

    /**
     * The v2 speech sub-path (sub-project D, spec §9): chunk 0 (the first
     * chunk_seconds of audio) is transcribed synchronously — today's
     * latency, now multilingual (chirp_3, auto language detect, phrase
     * hints) and budget-metered (v2 has NO free tier: every audio-bearing
     * post bills from the first second once the switch is on). Candidate-
     * bearing posts longer than one chunk additionally persist extension
     * chunks for TranscribeExtendedAudioJob. Fail-closed: v2 on but
     * unconfigured skips — it NEVER falls back to v1.
     *
     * @return array{0: int, 1: int, 2: list<string>} [created, updated, markers]
     */
    private function speechV2Pass(ContentItem|Story $target, LocalMediaAsset $video, string $correlationId, int $retryCount): array
    {
        if (! $this->speechV2->isConfigured()) {
            return [0, 0, ['speech:v2-not-configured']];
        }

        if (! $this->chunker->isAvailable()) {
            return [0, 0, ['speech:ffmpeg-unavailable']];
        }

        $audioBytes = $this->chunker->extractChunk($video->tempPath, 0);

        if ($audioBytes === null) {
            // Muted/undecodable media — unavailable, never fabricated.
            return [0, 0, ['speech:audio-extraction-failed']];
        }

        // Consulted BEFORE spending (house convention — v2 bills per call).
        if ($this->breaker->shouldSkip(SourceRegistry::GOOGLE_SPEECH_TO_TEXT)) {
            return [0, 0, ['speech:provider-error']];
        }

        $tenantId = (int) $target->tenant_id;
        $candidates = $this->candidateScope->forTarget($target);
        $decision = $this->budget->allows(self::SPEECH_CAPABILITY, $tenantId, 1, $candidates->priority);

        if (! $decision->allowed) {
            if ($decision->reason !== 'read-only') {
                $this->budget->record(self::SPEECH_CAPABILITY, $tenantId, 0, postsSkippedBudget: 1);
            }

            return [0, 0, ['speech:budget-exhausted']];
        }

        $phrases = $this->phrases->build($candidates);
        $markers = [];
        $created = 0;
        $updated = 0;

        try {
            $v2Result = null;

            [$created, $updated] = $this->annotate(
                $target,
                SourceRegistry::GOOGLE_SPEECH_TO_TEXT,
                'speech.recognize',
                $correlationId,
                $retryCount,
                function () use (&$v2Result, $audioBytes, $phrases): NormalizedBatch {
                    $v2Result = $this->speechV2->recognize($audioBytes, $phrases);

                    return $this->normalizer->transcriptChunkBatch(
                        $this->joinedTranscript($v2Result),
                        0,
                        $this->chunkConfidence($v2Result),
                    );
                },
            );

            $this->budget->record(self::SPEECH_CAPABILITY, $tenantId, 1, postsProcessed: 1);

            $text = $v2Result !== null ? $this->joinedTranscript($v2Result) : '';

            if ($target instanceof ContentItem && $v2Result !== null && trim($text) !== '') {
                // The sync chunk writes the transcript row FIRST; the async
                // job appends and re-stitches. Stories: detections-only
                // (documented v1 limitation, spec §16).
                $this->transcripts->apply($target, [new ChunkTranscript(
                    ordinal: 0,
                    offsetMs: 0,
                    durationMs: ($v2Result->billedSeconds ?? (int) config('qds.enrichment.speech.chunk_seconds')) * 1000,
                    text: $text,
                    languageCode: $this->chunkLanguage($v2Result),
                    confidence: $this->chunkConfidence($v2Result),
                )]);
            }
        } catch (ProviderCallException) {
            // A transient speech failure must NOT fail the whole run (v1
            // posture). The attempt may still have billed — counted
            // conservatively so caps never drift loose.
            $this->budget->record(self::SPEECH_CAPABILITY, $tenantId, 1);
            $markers[] = 'speech:provider-error';
        }

        // Extension tier (chunks 1..N): candidate-bearing posts only —
        // non-candidate posts never pay beyond chunk 0 (spec §9).
        if (! $candidates->isEmpty()) {
            $queued = $this->persistExtensionChunks($target, $video->tempPath);

            if ($queued > 0) {
                $markers[] = 'speech:chunks-queued='.$queued;
                TranscribeExtendedAudioJob::dispatch(
                    $target instanceof ContentItem ? 'content' : 'story',
                    $target->id,
                    $correlationId,
                );
            }
        }

        return [$created, $updated, $markers];
    }

    /**
     * Persist extension chunks (ordinals 1..N) while the video temp file
     * still exists. Two ceilings, both restated from config so neither can
     * silently drift: max_minutes bounds the audio scanned, and
     * per_post_units - 1 bounds the chunks that can EVER be billed (chunk
     * 0 already billed synchronously) — a chunk the budget ceiling can
     * never pay for is never persisted.
     */
    private function persistExtensionChunks(ContentItem|Story $target, string $videoPath): int
    {
        $chunkSeconds = (int) config('qds.enrichment.speech.chunk_seconds');
        $maxSeconds = ((int) config('qds.enrichment.speech.max_minutes')) * 60;
        $maxOrdinal = min(
            $this->chunker->chunkCount((float) $maxSeconds) - 1,
            (int) config('qds.ai_budget.capabilities.speech_transcription.per_post_units') - 1,
        );

        $queued = 0;

        for ($ordinal = 1; $ordinal <= $maxOrdinal; $ordinal++) {
            $bytes = $this->chunker->extractChunk($videoPath, $ordinal);

            if ($bytes === null) {
                break; // past the end of the audio (or ffmpeg failure) — stop.
            }

            $this->chunkWriter->persist($target, $ordinal, $ordinal * $chunkSeconds * 1000, $chunkSeconds * 1000, $bytes);
            $queued++;
        }

        return $queued;
    }

    /** All result transcripts of one chunk, joined — the chunk's text. */
    private function joinedTranscript(SpeechV2Result $result): string
    {
        $parts = [];

        foreach ($result->results as $row) {
            $part = trim((string) ($row['transcript'] ?? ''));

            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return implode(' ', $parts);
    }

    /** The MINIMUM non-null confidence across the chunk's results — conservative. */
    private function chunkConfidence(SpeechV2Result $result): ?float
    {
        $min = null;

        foreach ($result->results as $row) {
            $confidence = $row['confidence'] ?? null;

            if (is_float($confidence) || is_int($confidence)) {
                $min = $min === null ? (float) $confidence : min($min, (float) $confidence);
            }
        }

        return $min;
    }

    /** The first result's detected language — the chunk-level code (≤55 s chunks). */
    private function chunkLanguage(SpeechV2Result $result): ?string
    {
        $code = $result->results[0]['languageCode'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }
}
