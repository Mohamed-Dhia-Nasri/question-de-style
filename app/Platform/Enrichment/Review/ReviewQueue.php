<?php

namespace App\Platform\Enrichment\Review;

use App\Modules\Monitoring\Models\ContentHashtag;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The human review queue (DP-004). The queue is a QUERY, not a table
 * (canonical pattern: the *_review_queue_index expression indexes over the
 * ConfidenceAssessment envelope) — low-confidence AI_ASSESSED outputs plus
 * unresolved ambiguous hashtag matches.
 *
 * Kinds served: seeded attribution (mentions), brand/product recognition,
 * sentiment, hashtag ambiguity. Campaign matching and shipment-to-content
 * matching (REQ-M3-008) surface here through the mention items whose
 * signals carry the shipment/campaign evidence — their write path is
 * Module 3 / P3.
 */
class ReviewQueue
{
    public const KINDS = ['mention', 'recognition', 'sentiment', 'hashtag'];

    /**
     * @param  array{kind?: string|null, confidence?: list<string>|null, limit?: int}  $filters
     * @return Collection<int, array{kind: string, item: Model}>
     */
    public function items(array $filters = []): Collection
    {
        $kind = $filters['kind'] ?? null;
        $limit = max(1, (int) ($filters['limit'] ?? 100));

        $items = collect();

        if ($kind === null || $kind === 'mention') {
            $items = $items->concat(
                $this->envelopeQueue(Mention::query(), 'classification', $filters)
                    ->with(['contentItem', 'story', 'monitoredSubject'])
                    ->limit($limit)->get()
                    ->map(static fn (Mention $m): array => ['kind' => 'mention', 'item' => $m]),
            );
        }

        if ($kind === null || $kind === 'recognition') {
            $items = $items->concat(
                $this->envelopeQueue(RecognitionDetection::query(), 'assessment', $filters)
                    ->with(['contentItem', 'story'])
                    ->limit($limit)->get()
                    ->map(static fn (RecognitionDetection $d): array => ['kind' => 'recognition', 'item' => $d]),
            );
        }

        if ($kind === null || $kind === 'sentiment') {
            $items = $items->concat(
                $this->envelopeQueue(SentimentAnalysis::query(), 'assessment', $filters)
                    ->with(['contentItem'])
                    ->limit($limit)->get()
                    ->map(static fn (SentimentAnalysis $s): array => ['kind' => 'sentiment', 'item' => $s]),
            );
        }

        if ($kind === null || $kind === 'hashtag') {
            $items = $items->concat(
                ContentHashtag::query()
                    ->where('is_ambiguous', true)
                    ->whereNull('resolved_at')
                    ->with('contentItem')
                    ->orderBy('id')
                    ->limit($limit)->get()
                    ->map(static fn (ContentHashtag $h): array => ['kind' => 'hashtag', 'item' => $h]),
            );
        }

        return $items->take($limit)->values();
    }

    /** Total pending count per kind (queue badges / monitoring). */
    /** @return array<string, int> */
    public function counts(): array
    {
        return [
            'mention' => $this->envelopeQueue(Mention::query(), 'classification', [])->count(),
            'recognition' => $this->envelopeQueue(RecognitionDetection::query(), 'assessment', [])->count(),
            'sentiment' => $this->envelopeQueue(SentimentAnalysis::query(), 'assessment', [])->count(),
            'hashtag' => ContentHashtag::query()->where('is_ambiguous', true)->whereNull('resolved_at')->count(),
        ];
    }

    /**
     * The canonical review predicate: AI_ASSESSED envelopes at LOW/UNKNOWN
     * confidence (ConfidenceAssessment::needsHumanReview()), served by the
     * *_review_queue_index expression indexes.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array{confidence?: list<string>|null}  $filters
     * @return Builder<TModel>
     */
    private function envelopeQueue(Builder $query, string $envelopeColumn, array $filters): Builder
    {
        $levels = $filters['confidence'] ?? ['LOW', 'UNKNOWN'];

        return $query
            ->where(DB::raw("{$envelopeColumn}->>'verificationStatus'"), 'AI_ASSESSED')
            ->whereIn(DB::raw("{$envelopeColumn}->>'confidenceLevel'"), $levels)
            ->orderBy('id');
    }
}
