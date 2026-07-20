<?php

namespace App\Platform\Enrichment\Speech\Jobs;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\SpeechAudioChunk;
use App\Modules\Monitoring\Models\Story;
use App\Platform\AiBudget\AiBudgetGuard;
use App\Platform\Enrichment\Attribution\AttributionService;
use App\Platform\Enrichment\Http\GoogleSpeechV2Client;
use App\Platform\Enrichment\Http\SpeechV2Result;
use App\Platform\Enrichment\Recognition\RecognitionNormalizer;
use App\Platform\Enrichment\Recognition\RecognitionService;
use App\Platform\Enrichment\Speech\ChunkTranscript;
use App\Platform\Enrichment\Speech\SpeechAudioChunkWriter;
use App\Platform\Enrichment\Speech\SpeechPhraseHints;
use App\Platform\Enrichment\Speech\SpeechTranscriptWriter;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateScope;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Jobs\Concerns\IngestionJobBehaviour;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\Observability\ProviderCircuitBreaker;
use App\Platform\Ingestion\Persistence\PersistenceResult;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Async transcription of a post's persisted extension chunks (sub-project
 * D, spec §9/§10): per pending chunk — breaker consult, cumulative budget
 * allows, v2 recognize, SPOKEN_BRAND mining through the shared
 * recognition upsert, transcript append + re-stitch (content items only),
 * then row+blob deletion. After the last chunk: ONE
 * AttributionService::enrich re-classification inside the same tenant
 * context (the qds:visual-match-backfill precedent — this job is
 * enrich()'s third caller).
 *
 * Failure semantics: transient → chunks stay pending, release/backoff
 * (IngestionJobBehaviour); permanent → THAT chunk goes failed (its blob
 * stays for qds:prune-audio-chunks) and the rest still run. A speech
 * failure never fails an enrichment run — chunk 0's detections and
 * transcript already landed in the pipeline.
 */
final class TranscribeExtendedAudioJob implements ShouldBeUnique, ShouldQueue
{
    use IngestionJobBehaviour;
    use Queueable;

    private const CAPABILITY = 'speech_transcription';

    /** Deterministic waits: breaker cool-off / budget-window retry (seconds). */
    private const BREAKER_RELEASE_SECONDS = 300;

    private const BUDGET_RELEASE_SECONDS = 3600;

    public int $tries = 4;

    public int $timeout = 300;

    /** Speech jobs run outside monitoring cycles. */
    public readonly ?int $cycleId;

    public function __construct(
        public readonly string $targetType, // 'content'|'story'
        public readonly int $targetId,
        public readonly ?string $correlationId = null,
    ) {
        $this->cycleId = null;
        $this->onQueue((string) config('qds.enrichment.speech.queue'));
    }

    public function uniqueId(): string
    {
        return "speech-ext:{$this->targetType}:{$this->targetId}";
    }

    public function uniqueFor(): int
    {
        return $this->timeout + 60;
    }

    public function handle(
        GoogleSpeechV2Client $speechV2,
        RecognitionService $recognition,
        RecognitionNormalizer $normalizer,
        SpeechTranscriptWriter $transcripts,
        SpeechAudioChunkWriter $chunkWriter,
        SpeechPhraseHints $phrases,
        CandidateScope $candidates,
        AiBudgetGuard $budget,
        ProviderCircuitBreaker $breaker,
        ProviderCallRecorder $recorder,
        AttributionService $attribution,
    ): void {
        $this->attachLogContext();

        if (! (bool) config('qds.enrichment.speech.v2_enabled')) {
            return; // feature dark — chunks stay for the orphan prune
        }

        $target = $this->targetType === 'content'
            ? ContentItem::query()->find($this->targetId)
            : Story::query()->find($this->targetId);

        if ($target === null) {
            return; // stale job — the content was deleted/erased
        }

        // ADR-0019: run under the target's tenant so every write (chunks,
        // detections, transcript, mentions) stamps the right owner.
        app(TenantContext::class)->runAs(
            $target->tenant_id,
            fn () => $this->transcribePendingChunks(
                $target, $speechV2, $recognition, $normalizer, $transcripts,
                $chunkWriter, $phrases, $candidates, $budget, $breaker,
                $recorder, $attribution,
            ),
        );
    }

    private function transcribePendingChunks(
        ContentItem|Story $target,
        GoogleSpeechV2Client $speechV2,
        RecognitionService $recognition,
        RecognitionNormalizer $normalizer,
        SpeechTranscriptWriter $transcripts,
        SpeechAudioChunkWriter $chunkWriter,
        SpeechPhraseHints $phrases,
        CandidateScope $candidates,
        AiBudgetGuard $budget,
        ProviderCircuitBreaker $breaker,
        ProviderCallRecorder $recorder,
        AttributionService $attribution,
    ): void {
        if (! $speechV2->isConfigured()) {
            return; // fail-closed, like the pipeline path; prune reclaims blobs
        }

        $chunks = SpeechAudioChunk::query()
            ->where('owner_type', $target->getMorphClass())
            ->where('owner_id', $target->id)
            ->where('status', 'pending')
            ->orderBy('ordinal')
            ->get();

        if ($chunks->isEmpty()) {
            return; // idempotency: a retried job after full success is a no-op
        }

        $set = $candidates->forTarget($target);
        $phraseList = $phrases->build($set);
        $tenantId = (int) $target->tenant_id;
        $correlationId = $this->correlationId ?? $this->uniqueId();
        $transcribed = 0;

        foreach ($chunks as $chunk) {
            // Consulted BEFORE spending (house convention).
            if ($breaker->shouldSkip(SourceRegistry::GOOGLE_SPEECH_TO_TEXT)) {
                $this->release(self::BREAKER_RELEASE_SECONDS); // chunks stay pending

                return;
            }

            // Cumulative units — chunk 0 billed sync + this chunk's ordinal
            // — make the guard's per_post_units ceiling actually bind
            // across executions (the VLM ledger pattern, spec §10/§11; a
            // flat allows(1) never would).
            $decision = $budget->allows(self::CAPABILITY, $tenantId, $chunk->ordinal + 1, $set->priority);

            if (! $decision->allowed) {
                if ($decision->reason !== 'read-only') {
                    $budget->record(self::CAPABILITY, $tenantId, 0, postsSkippedBudget: 1);
                }

                // The window may reset (daily caps): bounded retries; tries
                // exhausted leaves chunks pending for the orphan prune.
                $this->release(self::BUDGET_RELEASE_SECONDS);

                return;
            }

            $bytes = Storage::disk($chunk->storage_disk)->get($chunk->storage_path);

            if (! is_string($bytes) || $bytes === '') {
                // Blob lost between persist and job — unavailable, never
                // fabricated; the row records the fact.
                $chunk->update(['status' => 'failed']);

                continue;
            }

            $context = $recorder->start(
                SourceRegistry::GOOGLE_SPEECH_TO_TEXT,
                'speech.recognize',
                $correlationId,
                null,
                $target->platform_account_id,
                max(0, $this->attempts() - 1),
            );

            try {
                $result = $speechV2->recognize($bytes, $phraseList);
            } catch (ProviderCallException $e) {
                $recorder->recordFailure($context, $e);
                // The attempt may have billed — count it conservatively so
                // caps never drift loose.
                $budget->record(self::CAPABILITY, $tenantId, 1);

                if ($e->category->isTransient()) {
                    // Chunk stays pending; Retry-After release or rethrow →
                    // queue backoff (IngestionJobBehaviour).
                    $this->handleProviderFailure($e);

                    return;
                }

                // Permanent for THIS chunk; the rest still get their shot.
                $chunk->update(['status' => 'failed']);

                continue;
            }

            $budget->record(self::CAPABILITY, $tenantId, 1);

            $text = $this->joinedTranscript($result);
            $batch = $normalizer->transcriptChunkBatch($text, (int) $chunk->ordinal, $this->chunkConfidence($result));

            $persistStart = microtime(true);
            [$created, $updated] = $recognition->persist($target, SourceRegistry::GOOGLE_SPEECH_TO_TEXT, $batch);
            $recorder->recordCompletion($context, $batch, new PersistenceResult(
                created: $created,
                duplicates: $updated,
                persistenceMs: (microtime(true) - $persistStart) * 1000,
            ));

            if ($target instanceof ContentItem && trim($text) !== '') {
                // Durable BEFORE the chunk row/blob is deleted: a crash
                // between the two re-transcribes at most one chunk, and
                // apply() replaces its segment idempotently.
                $transcripts->apply($target, [new ChunkTranscript(
                    ordinal: (int) $chunk->ordinal,
                    offsetMs: (int) $chunk->offset_ms,
                    durationMs: $result->billedSeconds !== null ? $result->billedSeconds * 1000 : (int) $chunk->duration_ms,
                    text: $text,
                    languageCode: $this->chunkLanguage($result),
                    confidence: $this->chunkConfidence($result),
                )]);
            }

            $chunkWriter->deleteChunk($chunk); // row + blob (spec §8.3)
            $transcribed++;
        }

        if ($transcribed > 0) {
            // ONE re-classification after the last chunk — the mention
            // updates inside the same tenant context (backfill precedent).
            $attribution->enrich($target);
        }
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
