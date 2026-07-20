<?php

namespace App\Platform\Enrichment\Speech;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Persists the speech-v2 transcript of one ContentItem (sub-project D,
 * spec §9): ONE row per item under provider SRC-google-speech-to-text on
 * the narrowed (content_item_id, provider) identity (Task 3 migration).
 * `language` is MUTABLE transcript metadata — the dominant detected
 * language by summed chunk duration — while per-chunk languages live in
 * the segments (list<{start, dur, text, language, chunk}>; start/dur are
 * second-strings with millisecond precision, chunk is the int ordinal).
 * The sync chunk writes the row first; TranscribeExtendedAudioJob appends
 * and re-stitches. Stories keep detections-only (spec §16). Unlike the
 * YouTube enricher (whose rows are immutable caches), a lost INSERT race
 * here MERGES into the winner — both writers are additive. The whole
 * read-merge-save runs in one transaction with the row locked FOR UPDATE
 * so a racing writer serializes behind it instead of clobbering freshly
 * appended segments whose chunk blobs are already deleted.
 */
final class SpeechTranscriptWriter
{
    public const SOURCE_VERSION = 'google-speech-to-text-v2';

    /** @param list<ChunkTranscript> $chunks */
    public function apply(ContentItem $item, array $chunks): ContentTranscript
    {
        if ($chunks === []) {
            throw new InvalidArgumentException('apply() requires at least one chunk transcript.');
        }

        $identity = [
            'content_item_id' => $item->id,
            'provider' => SourceRegistry::GOOGLE_SPEECH_TO_TEXT,
        ];

        // Lost-update guard: an unlocked read-merge-save would let a
        // chunk-0 sync write racing an in-flight extension job clobber
        // freshly appended segments (unrecoverable — the chunk blobs are
        // already deleted). The FOR UPDATE reload serializes writers on
        // the row for the duration of the merge.
        return DB::transaction(function () use ($identity, $chunks): ContentTranscript {
            $row = ContentTranscript::query()->lockForUpdate()->firstOrNew($identity);
            $this->merge($row, $chunks);

            try {
                // A SAVEPOINT so an INSERT collision poisons only this save,
                // never the wrapping transaction (YouTubeTranscriptEnricher
                // pattern) — the recovery re-query below depends on it.
                ContentTranscript::query()->withSavepointIfNeeded(fn () => $row->save());
            } catch (UniqueConstraintViolationException) {
                // A concurrent writer won the INSERT race on the narrowed
                // (content_item_id, provider) key: reload the winner —
                // locked — and merge our chunks ON TOP of its segments,
                // never clobber.
                $row = ContentTranscript::query()->lockForUpdate()->where($identity)->firstOrFail();
                $this->merge($row, $chunks);
                $row->save();
            }

            return $row;
        });
    }

    /** @param list<ChunkTranscript> $chunks */
    private function merge(ContentTranscript $row, array $chunks): void
    {
        $incoming = [];

        foreach ($chunks as $chunk) {
            // Re-transcription replaces: last write per ordinal wins.
            $incoming[$chunk->ordinal] = [
                'start' => sprintf('%.3f', $chunk->offsetMs / 1000),
                'dur' => sprintf('%.3f', $chunk->durationMs / 1000),
                'text' => trim($chunk->text),
                'language' => $chunk->languageCode ?? 'und',
                'chunk' => $chunk->ordinal,
            ];
        }

        $merged = $incoming;

        foreach ((array) ($row->segments ?? []) as $segment) {
            if (is_array($segment)
                && is_int($segment['chunk'] ?? null)
                && ! array_key_exists($segment['chunk'], $incoming)) {
                $merged[$segment['chunk']] = $segment;
            }
        }

        ksort($merged);
        $segments = array_values($merged);

        $text = trim(implode(' ', array_values(array_filter(
            array_column($segments, 'text'),
            static fn (string $part): bool => $part !== '',
        ))));

        $row->fill([
            'language' => $this->dominantLanguage($segments),
            'status' => ContentTranscript::STATUS_AVAILABLE,
            'text' => $text,
            'segments' => $segments,
            'checksum' => hash('sha256', $text),
            'fetched_at' => CarbonImmutable::now(),
            'provenance' => new Provenance(SourceRegistry::GOOGLE_SPEECH_TO_TEXT, CarbonImmutable::now(), self::SOURCE_VERSION),
        ]);
    }

    /**
     * The dominant language by summed chunk duration (spec §9 "by billed
     * seconds"; the caller feeds billed seconds into durationMs when the
     * provider reported them). Ties break to the lexicographically
     * smallest code — deterministic re-stitching (PHP sorts are stable
     * since 8.0: ksort first, then the stable arsort keeps key order for
     * equal totals).
     *
     * @param  list<array{start: string, dur: string, text: string, language: string, chunk: int}>  $segments
     */
    private function dominantLanguage(array $segments): string
    {
        $totals = [];

        foreach ($segments as $segment) {
            $language = (string) ($segment['language'] ?? 'und');
            $totals[$language] = ($totals[$language] ?? 0.0) + (float) $segment['dur'];
        }

        if ($totals === []) {
            return 'und';
        }

        ksort($totals);
        arsort($totals);

        return (string) array_key_first($totals);
    }
}
