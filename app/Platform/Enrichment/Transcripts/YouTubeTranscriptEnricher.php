<?php

namespace App\Platform\Enrichment\Transcripts;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Http\ApifyClient;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The transcript pipeline stage (sub-project B, ADR-0028): ensures one
 * YouTube video's captions are persisted as a ContentTranscript BEFORE
 * recognition runs — recognition only ever consumes persisted rows and
 * never triggers this billed provider itself.
 *
 * Billing doctrine: at most ONE successful actor run per video, ever.
 *  - available row  → completed:cached (no call);
 *  - unavailable row → skipped:no-captions (negative cache, no call);
 *  - successful run, no captions → persist the unavailable row;
 *  - transport/provider error → persist NOTHING (skipped:provider-error;
 *    the next enrichment run may retry — that is correct, not a leak).
 */
class YouTubeTranscriptEnricher
{
    public function __construct(
        private readonly ApifyClient $client,
        private readonly ProviderCallRecorder $recorder,
    ) {}

    public function enrich(ContentItem|Story $target, string $correlationId, int $retryCount): string
    {
        if (! $target instanceof ContentItem || $target->platform !== Platform::YouTube) {
            return 'skipped:not-applicable';
        }

        if (! (bool) config('qds.ingestion.youtube_transcript.enabled')) {
            return 'skipped:disabled';
        }

        // Two-layer duplicate-bill guard (billing invariant: at most ONE
        // successful actor run per video, ever). The queued path is
        // protected by EnrichContentItemJob's ShouldBeUnique (one job per
        // content item at a time); this synchronous EnrichmentService path
        // has no such lock, so two concurrent callers can both pass this
        // null check and both bill. Because control only reaches the
        // persist step below when THIS check found nothing, any row that
        // shows up there can only be a concurrent winner — never ours to
        // overwrite. Two race windows are covered: the firstOrNew() SELECT
        // finding an existing() row (winner committed before our SELECT)
        // and, failing that, save() throwing UniqueConstraintViolationException
        // (winner committed between our SELECT and our INSERT). Both report
        // the winner's outcome instead of crashing or clobbering it — the
        // residual double-bill on this unwired path is accepted and
        // documented; the crash/clobber is not. (firstOrNew()+save(), not
        // updateOrCreate(): its built-in createOrFirst() would silently
        // overwrite the winner's row with our stale data instead of
        // reporting it — RecognitionService::persist() uses this same
        // catch-and-recover pattern.)
        $existing = ContentTranscript::query()
            ->where('content_item_id', $target->id)
            ->where('provider', SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT)
            ->where('language', 'und')
            ->first();

        if ($existing !== null) {
            return $existing->status === ContentTranscript::STATUS_AVAILABLE
                ? 'completed:cached'
                : 'skipped:no-captions';
        }

        if (! is_string($target->external_id) || $target->external_id === '') {
            return 'skipped:no-video-id';
        }

        $context = $this->recorder->start(
            SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
            'transcript.fetch',
            $correlationId,
            null,
            $target->platform_account_id,
            $retryCount,
        );

        try {
            $response = $this->client->runActor(
                SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
                (string) config('services.apify.actors.youtube_transcript'),
                ['videoUrl' => "https://www.youtube.com/watch?v={$target->external_id}"],
            );
        } catch (ProviderCallException $e) {
            $this->recorder->recordFailure($context, $e);

            return 'skipped:provider-error';
        }

        $segments = $this->segmentsFrom($response->items);
        $text = trim(implode(' ', array_column($segments, 'text')));
        $identity = [
            'content_item_id' => $target->id,
            'language' => 'und',
            'provider' => SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
        ];
        $provenance = new Provenance(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, CarbonImmutable::now(), 'youtube-transcript-v1');

        if ($text === '') {
            // Successful run, no captions: negative-cache it (never re-bill).
            $row = ContentTranscript::query()->firstOrNew($identity);

            if ($row->exists) {
                // Pre-INSERT race window: a concurrent run's row already
                // landed between the existing-row check at the top of
                // enrich() (which found nothing — that's the only way
                // control reaches this persist step) and THIS firstOrNew()
                // SELECT. An existing row here can only be a concurrent
                // winner, so it must never be fill()+save()-overwritten —
                // that could flip an available row to unavailable and
                // destroy a good transcript. Our own actor call still
                // happened, so it stays telemetered.
                $this->recorder->recordOperation($context, $response, 0);

                return $this->summaryForRow($row);
            }

            $row->fill([
                'status' => ContentTranscript::STATUS_UNAVAILABLE,
                'text' => null,
                'segments' => null,
                'checksum' => null,
                'fetched_at' => CarbonImmutable::now(),
                'provenance' => $provenance,
            ]);

            try {
                // A SAVEPOINT (when already inside a transaction) so a
                // collision here rolls back only this insert, never the
                // caller's wider unit of work.
                ContentTranscript::query()->withSavepointIfNeeded(fn () => $row->save());
                $this->recorder->recordOperation($context, $response, 0);

                return 'skipped:no-captions';
            } catch (UniqueConstraintViolationException) {
                // Post-SELECT race window: a concurrent run won the INSERT
                // race after our own SELECT found nothing. Our actor call
                // really happened, so it must stay telemetered — then
                // report the WINNER's row status, not ours.
                $this->recorder->recordOperation($context, $response, 0);

                return $this->summaryForConcurrentWinner($identity);
            }
        }

        $row = ContentTranscript::query()->firstOrNew($identity);

        if ($row->exists) {
            // Same pre-INSERT race window as above.
            $this->recorder->recordOperation($context, $response, 1);

            return $this->summaryForRow($row);
        }

        $row->fill([
            'status' => ContentTranscript::STATUS_AVAILABLE,
            'text' => $text,
            'segments' => $segments,
            'checksum' => hash('sha256', $text),
            'fetched_at' => CarbonImmutable::now(),
            'provenance' => $provenance,
        ]);

        try {
            ContentTranscript::query()->withSavepointIfNeeded(fn () => $row->save());
            $this->recorder->recordOperation($context, $response, 1);

            return 'completed:fetched';
        } catch (UniqueConstraintViolationException) {
            // Same recovery as above: a concurrent run won, our billed call
            // still gets telemetered, and we report the winner's outcome.
            $this->recorder->recordOperation($context, $response, 1);

            return $this->summaryForConcurrentWinner($identity);
        }
    }

    /**
     * Re-read the row a concurrent run persisted first (by the same
     * content_item_id/language/provider identity) and report ITS status
     * rather than ours. A null read here is pathological — the winner's
     * row should exist by definition of losing a unique-key race — but is
     * handled without fabricating a result.
     *
     * @param  array{content_item_id: int, language: string, provider: string}  $identity
     */
    private function summaryForConcurrentWinner(array $identity): string
    {
        $winner = ContentTranscript::query()->where($identity)->first();

        return $winner === null ? 'skipped:provider-error' : $this->summaryForRow($winner);
    }

    /** Map an already-loaded transcript row to its outcome summary. */
    private function summaryForRow(ContentTranscript $row): string
    {
        return $row->status === ContentTranscript::STATUS_AVAILABLE
            ? 'completed:cached'
            : 'skipped:no-captions';
    }

    /**
     * Tolerant parse of the actor's dataset items — each item may carry a
     * `data` list of {start, dur, text} caption cues. Anything malformed is
     * skipped (never fabricated).
     *
     * @param  list<mixed>  $items
     * @return list<array{start: string, dur: string, text: string}>
     */
    private function segmentsFrom(array $items): array
    {
        $segments = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach ((array) ($item['data'] ?? []) as $segment) {
                if (is_array($segment) && is_string($segment['text'] ?? null) && trim($segment['text']) !== '') {
                    $segments[] = [
                        'start' => (string) ($segment['start'] ?? ''),
                        'dur' => (string) ($segment['dur'] ?? ''),
                        'text' => trim($segment['text']),
                    ];
                }
            }
        }

        return $segments;
    }
}
