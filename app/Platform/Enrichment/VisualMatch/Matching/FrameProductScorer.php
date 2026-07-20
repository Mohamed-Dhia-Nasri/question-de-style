<?php

namespace App\Platform\Enrichment\VisualMatch\Matching;

use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Frames\PreparedFrame;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * ONE exact-scan SQL statement per run (sub-project C, spec §8): for every
 * (embedded frame, candidate product) pair, the best cosine similarity
 * across that product's reference photos at the requested model_version.
 * pgvector's `<=>` is cosine DISTANCE (verified, spec §18), so similarity
 * = 1 - (a <=> b) and ORDER BY distance ASC is best-first. Exact scan on
 * purpose — candidate sets are btree-pre-filtered to double digits, where
 * exact beats ANN, loses zero recall, and stays fully deterministic (photo
 * ties break on lower photo id via the fully-specified ORDER BY).
 *
 * Frames without a cached keyframe_embeddings row (transient embed
 * failure) simply drop out of the join — omitted, never fabricated.
 */
final class FrameProductScorer
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  list<PreparedFrame>  $frames
     * @param  list<Candidate>  $matchable
     * @return list<CandidateScores> one per matchable candidate, candidate order preserved
     */
    public function score(array $frames, array $matchable, string $modelVersion): array
    {
        if ($matchable === []) {
            return [];
        }

        /** @var array<int, PreparedFrame> $frameByKeyframeId */
        $frameByKeyframeId = [];
        foreach ($frames as $frame) {
            $frameByKeyframeId[(int) $frame->keyframe->getKey()] = $frame;
        }

        $best = $frameByKeyframeId === [] ? [] : $this->bestPhotoPerFrame(
            array_keys($frameByKeyframeId),
            array_map(fn (Candidate $candidate): int => $candidate->productId, $matchable),
            $modelVersion,
        );

        $results = [];
        foreach ($matchable as $candidate) {
            $scores = [];
            foreach ($best[$candidate->productId] ?? [] as $keyframeId => $row) {
                $frame = $frameByKeyframeId[$keyframeId];
                $scores[] = new FrameScore(
                    keyframeId: $keyframeId,
                    ordinal: (int) $frame->keyframe->ordinal,
                    timestampMs: $frame->keyframe->timestamp_ms === null ? null : (int) $frame->keyframe->timestamp_ms,
                    similarity: (float) $row->similarity,
                    photoId: (int) $row->photo_id,
                    representedFrames: $frame->representedFrames,
                );
            }
            usort($scores, fn (FrameScore $a, FrameScore $b): int => $a->ordinal <=> $b->ordinal);
            $results[] = new CandidateScores($candidate, $scores);
        }

        return $results;
    }

    /**
     * @param  list<int>  $keyframeIds
     * @param  list<int>  $productIds
     * @return array<int, array<int, object>> product id → keyframe id → winning row {photo_id, similarity}
     */
    private function bestPhotoPerFrame(array $keyframeIds, array $productIds, string $modelVersion): array
    {
        $tenantId = $this->tenantContext->idOrFail();
        $framePlaceholders = implode(',', array_fill(0, count($keyframeIds), '?'));
        $productPlaceholders = implode(',', array_fill(0, count($productIds), '?'));

        $sql = <<<SQL
            SELECT scored.keyframe_id, scored.product_id, scored.photo_id, scored.similarity
            FROM (
                SELECT ke.keyframe_id,
                       prp.product_id,
                       pe.product_reference_photo_id AS photo_id,
                       1 - (ke.embedding <=> pe.embedding) AS similarity,
                       ROW_NUMBER() OVER (
                           PARTITION BY ke.keyframe_id, prp.product_id
                           ORDER BY ke.embedding <=> pe.embedding ASC, pe.product_reference_photo_id ASC
                       ) AS best_rank
                FROM keyframe_embeddings ke
                JOIN product_photo_embeddings pe
                    ON pe.tenant_id = ke.tenant_id AND pe.model_version = ke.model_version
                JOIN product_reference_photos prp
                    ON prp.id = pe.product_reference_photo_id AND prp.tenant_id = pe.tenant_id
                WHERE ke.tenant_id = ?
                  AND ke.model_version = ?
                  AND ke.keyframe_id IN ({$framePlaceholders})
                  AND prp.product_id IN ({$productPlaceholders})
            ) scored
            WHERE scored.best_rank = 1
            ORDER BY scored.product_id ASC, scored.keyframe_id ASC
        SQL;

        $rows = DB::select($sql, [$tenantId, $modelVersion, ...$keyframeIds, ...$productIds]);

        $best = [];
        foreach ($rows as $row) {
            $best[(int) $row->product_id][(int) $row->keyframe_id] = $row;
        }

        return $best;
    }
}
