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
            ContentTranscript::query()->updateOrCreate($identity, [
                'status' => ContentTranscript::STATUS_UNAVAILABLE,
                'text' => null,
                'segments' => null,
                'checksum' => null,
                'fetched_at' => CarbonImmutable::now(),
                'provenance' => $provenance,
            ]);
            $this->recorder->recordOperation($context, $response, 0);

            return 'skipped:no-captions';
        }

        ContentTranscript::query()->updateOrCreate($identity, [
            'status' => ContentTranscript::STATUS_AVAILABLE,
            'text' => $text,
            'segments' => $segments,
            'checksum' => hash('sha256', $text),
            'fetched_at' => CarbonImmutable::now(),
            'provenance' => $provenance,
        ]);
        $this->recorder->recordOperation($context, $response, 1);

        return 'completed:fetched';
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
