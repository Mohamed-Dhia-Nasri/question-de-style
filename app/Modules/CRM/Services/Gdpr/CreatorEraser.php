<?php

namespace App\Modules\CRM\Services\Gdpr;

use App\Modules\CRM\Models\Creator;
use App\Modules\Monitoring\Contracts\RosterEnrollment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Analytics\NeonAnalyticsService;
use App\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * GDPR data-subject erasure (P4 hardening, DP-005): deletes EVERYTHING the
 * platform holds about one creator — CRM profile, contacts, correspondence,
 * shipments, documents, tasks, monitored accounts, the monitoring history
 * collected from their public profiles (content, stories + archived media,
 * comments, mentions, enrichment artifacts incl. keyframes + transcripts +
 * visual/VLM run evidence + speech audio chunks,
 * metric snapshots), and the creator's rows in the analytics star schema.
 *
 * This is deliberately STRONGER than CreatorWriter::deleteCreator (the
 * operator's remove-a-stray tool, which monitoring HISTORY rightly blocks):
 * erasure is a legal obligation, so the append-only guards on
 * metric_snapshots (ADR-0003) and the analytics facts (ADR-0010) are opened
 * for DELETE via the transaction-local `qds.gdpr_erasure` gate — set only
 * here, only for the duration of the single erasure transaction.
 *
 * Deletes use the query builder (not Eloquent) on purpose: model-level
 * append-only guards (MetricSnapshot::booted) would otherwise throw, and no
 * model events should fire during a compliance purge. Audit logs are KEPT
 * (legitimate-interest trail); the erasure itself is recorded with counts
 * only — no personal data.
 */
class CreatorEraser
{
    public function __construct(
        private readonly RosterEnrollment $roster,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, int> rows deleted per table (files under 'media_files' / 'document_files' / 'keyframe_files')
     */
    public function erase(Creator $creator): array
    {
        $creatorId = $creator->id;
        $counts = [];
        $mediaPaths = [];
        $documentPaths = [];
        /** @var array<string, list<string>> $keyframePathsByDisk paths grouped by their own storage_disk (keyframes carry a per-row disk; media_files/document_files do not) */
        $keyframePathsByDisk = [];
        /** @var array<string, list<string>> $speechChunkPathsByDisk paths grouped by their own storage_disk (speech chunks follow the keyframe pattern — sub-project D) */
        $speechChunkPathsByDisk = [];

        DB::transaction(function () use ($creator, $creatorId, &$counts, &$mediaPaths, &$documentPaths, &$keyframePathsByDisk, &$speechChunkPathsByDisk): void {
            // Transaction-local gate for the append-only triggers
            // (metric_snapshots, fact_*). set_config(..., true) is scoped to
            // THIS transaction and is explicitly turned off before commit
            // below, so the gate never outlives the purge — even if erase()
            // is ever called nested inside an outer transaction (a SAVEPOINT,
            // which would not otherwise revert the GUC on release).
            DB::statement("SELECT set_config('qds.gdpr_erasure', 'on', true)");

            $accountIds = DB::table('platform_accounts')->where('creator_id', $creatorId)->pluck('id')->all();
            $subjectIds = DB::table('monitored_subjects')->where('creator_id', $creatorId)->pluck('id')->all();
            $contentIds = $this->ids('content_items', 'platform_account_id', $accountIds);
            $storyIds = $this->ids('stories', 'platform_account_id', $accountIds);
            $commentIds = $this->ids('comments', 'content_item_id', $contentIds);

            // File paths must be collected BEFORE the rows go; the files are
            // deleted after commit (never delete blobs a rollback would orphan).
            $mediaPaths = $storyIds === [] ? [] : DB::table('stories')
                ->whereIn('id', $storyIds)->whereNotNull('media_url')->pluck('media_url')->all();
            $documentPaths = DB::table('document_attachments')
                ->where('creator_id', $creatorId)->pluck('storage_url')->all();

            $mentionIds = $subjectIds === [] ? [] : DB::table('mentions')
                ->whereIn('monitored_subject_id', $subjectIds)->pluck('id')->all();
            $sentimentIds = $contentIds === [] && $commentIds === [] ? [] : DB::table('sentiment_analyses')
                ->where(fn ($q) => $q->whereIn('content_item_id', $contentIds)->orWhereIn('comment_id', $commentIds))
                ->pluck('id')->all();
            $recognitionIds = $contentIds === [] && $storyIds === [] ? [] : DB::table('recognition_detections')
                ->where(fn ($q) => $q->whereIn('content_item_id', $contentIds)->orWhereIn('story_id', $storyIds))
                ->pluck('id')->all();

            // Keyframes (sub-project B) are polymorphically owned by either a
            // ContentItem or a Story — both owner sets must be matched. Paths
            // are collected BEFORE the rows go, grouped by each row's own
            // storage_disk (unlike media_files/document_files, keyframes are
            // not all on one configured disk — Task 15's prune command reads
            // the same per-row column); the files are deleted after commit.
            $keyframeRows = ($contentIds === [] && $storyIds === []) ? [] : DB::table('keyframes')
                ->where(function ($q) use ($contentIds, $storyIds): void {
                    $q->where(function ($qq) use ($contentIds): void {
                        $qq->where('owner_type', (new ContentItem)->getMorphClass())->whereIn('owner_id', $contentIds);
                    })->orWhere(function ($qq) use ($storyIds): void {
                        $qq->where('owner_type', (new Story)->getMorphClass())->whereIn('owner_id', $storyIds);
                    });
                })
                ->get(['id', 'storage_disk', 'storage_path'])->all();
            foreach ($keyframeRows as $row) {
                $keyframePathsByDisk[$row->storage_disk][] = $row->storage_path;
            }

            // Speech audio chunks (sub-project D) are polymorphically owned
            // like keyframes and carry a per-row storage_disk. Paths are
            // collected BEFORE the rows go; blobs are deleted after commit.
            $speechChunkRows = ($contentIds === [] && $storyIds === []) ? [] : DB::table('speech_audio_chunks')
                ->where(function ($q) use ($contentIds, $storyIds): void {
                    $q->where(function ($qq) use ($contentIds): void {
                        $qq->where('owner_type', (new ContentItem)->getMorphClass())->whereIn('owner_id', $contentIds);
                    })->orWhere(function ($qq) use ($storyIds): void {
                        $qq->where('owner_type', (new Story)->getMorphClass())->whereIn('owner_id', $storyIds);
                    });
                })
                ->get(['id', 'storage_disk', 'storage_path'])->all();
            foreach ($speechChunkRows as $row) {
                $speechChunkPathsByDisk[$row->storage_disk][] = $row->storage_path;
            }

            // Review corrections hold 'original' payloads of the rows being
            // erased (captions, detected labels) — personal data too.
            $counts['review_actions'] =
                $this->deleteReviewActions(new SentimentAnalysis, $sentimentIds)
                + $this->deleteReviewActions(new RecognitionDetection, $recognitionIds)
                + $this->deleteReviewActions(new Mention, $mentionIds);

            // Enrichment + engagement artifacts anchored to the creator's
            // content, in FK dependency order.
            $counts['sentiment_analyses'] = $this->deleteByIds('sentiment_analyses', $sentimentIds);
            $counts['comments'] = $this->deleteByIds('comments', $commentIds);
            $counts['recognition_detections'] = $this->deleteByIds('recognition_detections', $recognitionIds);
            $counts['keyframes'] = $this->deleteByIds('keyframes', array_map('intval', array_column($keyframeRows, 'id')));
            $counts['content_transcripts'] = $this->deleteWhereIn('content_transcripts', 'content_item_id', $contentIds);
            $counts['content_hashtags'] = $this->deleteWhereIn('content_hashtags', 'content_item_id', $contentIds);
            $counts['emv_results'] = $this->deleteWhereIn('emv_results', 'content_item_id', $contentIds);
            $counts['reach_results'] = $this->deleteWhereIn('reach_results', 'content_item_id', $contentIds);
            $counts['enrichment_runs'] = ($contentIds === [] && $storyIds === []) ? 0 : DB::table('enrichment_runs')
                ->where(fn ($q) => $q->whereIn('content_item_id', $contentIds)->orWhereIn('story_id', $storyIds))
                ->delete();
            // VLM verification audit trail (sub-project D): runs are anchored
            // to the creator's content; per-candidate verdicts cascade from
            // runs at the DB. Deleted before visual_match_runs only for
            // tidiness — the anchor FK is nullOnDelete either way.
            $counts['vlm_verification_runs'] = ($contentIds === [] && $storyIds === []) ? 0 : DB::table('vlm_verification_runs')
                ->where(fn ($q) => $q->whereIn('content_item_id', $contentIds)->orWhereIn('story_id', $storyIds))
                ->delete();
            $counts['speech_audio_chunks'] = $this->deleteByIds('speech_audio_chunks', array_map('intval', array_column($speechChunkRows, 'id')));
            // Visual-match audit trail (sub-project C): runs are anchored to
            // the creator's content; candidates cascade from runs at the DB
            // (keyframe embeddings likewise cascade with the keyframes above).
            $counts['visual_match_runs'] = ($contentIds === [] && $storyIds === []) ? 0 : DB::table('visual_match_runs')
                ->where(fn ($q) => $q->whereIn('content_item_id', $contentIds)->orWhereIn('story_id', $storyIds))
                ->delete();
            $counts['mentions'] = $this->deleteByIds('mentions', $mentionIds);

            // Append-only history — passes only because the gate is on.
            $counts['metric_snapshots'] = ($accountIds === [] && $contentIds === []) ? 0 : DB::table('metric_snapshots')
                ->where(fn ($q) => $q->whereIn('platform_account_id', $accountIds)->orWhereIn('content_item_id', $contentIds))
                ->delete();

            // Analytics star schema: facts (gated), then creator-keyed
            // dimensions and rollups. Aggregated rollups (brand/campaign
            // level) recompute from the purged facts on the next refresh.
            $counts['analytics_facts'] =
                $this->deleteWhereIn('fact_content_metric', 'content_item_id', $contentIds)
                + DB::table('fact_content_metric')->where('creator_id', $creatorId)->delete()
                + DB::table('fact_creator_account')->where('creator_id', $creatorId)->delete()
                + $this->deleteWhereIn('fact_creator_account', 'platform_account_id', $accountIds)
                + DB::table('fact_mention')->where('creator_id', $creatorId)->delete()
                + $this->deleteWhereIn('fact_mention', 'monitored_subject_id', $subjectIds)
                + DB::table('fact_shipment')->where('creator_id', $creatorId)->delete()
                + DB::table('fact_seeding_content')->where('creator_id', $creatorId)->delete()
                + $this->deleteWhereIn('fact_seeding_content', 'content_item_id', $contentIds);
            $counts['analytics_dimensions'] =
                DB::table('dim_creator')->where('creator_id', $creatorId)->delete()
                + DB::table('dim_geo')->where('creator_id', $creatorId)->delete();

            // Monitoring content and roster configuration.
            $counts['stories'] = $this->deleteByIds('stories', $storyIds);
            $counts['content_items'] = $this->deleteByIds('content_items', $contentIds);
            $this->roster->withdraw($creator);
            $counts['monitored_subjects'] = count($subjectIds);

            // CRM-owned personal data. Campaign/seeding pivots cascade with
            // the creator row; ingestion_cycles.creator_id nulls on delete.
            $counts['shipments'] = DB::table('shipments')->where('creator_id', $creatorId)->delete();
            $counts['document_attachments'] = DB::table('document_attachments')->where('creator_id', $creatorId)->delete();
            $counts['tasks'] = DB::table('tasks')->where('creator_id', $creatorId)->delete();
            $counts['communication_logs'] = DB::table('communication_logs')->where('creator_id', $creatorId)->delete();
            $counts['brand_preferences'] = DB::table('brand_preferences')->where('creator_id', $creatorId)->delete();
            $counts['contacts'] = DB::table('contacts')->where('creator_id', $creatorId)->delete();
            $counts['platform_accounts'] = DB::table('platform_accounts')->where('creator_id', $creatorId)->delete();
            $counts['creators'] = DB::table('creators')->where('id', $creatorId)->delete();

            // Explicitly close the gate before commit as well (belt-and-braces
            // over the transaction-local scope).
            DB::statement("SELECT set_config('qds.gdpr_erasure', 'off', true)");
        });

        // Every rollup is a MATERIALIZED VIEW — rows cannot be deleted, and
        // several beyond the two creator-period views carry a creator's
        // contribution (rollup_seeding_by_shipment at per-shipment grain,
        // rollup_metric_by_geo via dim_geo). Recompute the WHOLE set from the
        // purged facts now rather than hand-picking, so no erased-creator data
        // lingers in a matview until the (config-gated) scheduled refresh.
        foreach (NeonAnalyticsService::ROLLUPS as $view) {
            DB::statement("REFRESH MATERIALIZED VIEW {$view}");
        }
        $counts['analytics_rollups_refreshed'] = count(NeonAnalyticsService::ROLLUPS);

        // Blobs go only after the rows are durably gone.
        $counts['media_files'] = $this->deleteFiles((string) config('qds.ingestion.media_disk'), $mediaPaths);
        $counts['document_files'] = $this->deleteFiles((string) config('qds.documents.disk'), $documentPaths);
        $counts['keyframe_files'] = 0;
        foreach ($keyframePathsByDisk as $disk => $paths) {
            $counts['keyframe_files'] += $this->deleteFiles($disk, $paths);
        }
        $counts['speech_chunk_files'] = 0;
        foreach ($speechChunkPathsByDisk as $disk => $paths) {
            $counts['speech_chunk_files'] += $this->deleteFiles($disk, $paths);
        }

        // A GDPR access export generated earlier is the single richest PII
        // artifact — purge the creator's dossiers synchronously rather than
        // leaving them for the daily retention sweep (DP-005).
        $counts['export_dossiers'] = $this->deleteExportDossiers($creator);

        $this->audit->record('creator.gdpr_erased', null, [
            'creator_id' => $creatorId,
            'counts' => $counts,
        ]);

        return $counts;
    }

    /**
     * @param  list<int|string>  $parentIds
     * @return list<int>
     */
    private function ids(string $table, string $column, array $parentIds): array
    {
        return $parentIds === []
            ? []
            : DB::table($table)->whereIn($column, $parentIds)->pluck('id')->all();
    }

    /** @param list<int> $ids */
    private function deleteByIds(string $table, array $ids): int
    {
        return $ids === [] ? 0 : DB::table($table)->whereIn('id', $ids)->delete();
    }

    /** @param list<int|string> $ids */
    private function deleteWhereIn(string $table, string $column, array $ids): int
    {
        return $ids === [] ? 0 : DB::table($table)->whereIn($column, $ids)->delete();
    }

    /** @param list<int> $reviewableIds */
    private function deleteReviewActions(object $model, array $reviewableIds): int
    {
        if ($reviewableIds === []) {
            return 0;
        }

        return DB::table('review_actions')
            ->where('reviewable_type', $model->getMorphClass())
            ->whereIn('reviewable_id', $reviewableIds)
            ->delete();
    }

    /** @param list<string> $paths */
    private function deleteFiles(string $disk, array $paths): int
    {
        $deleted = 0;

        foreach (array_filter($paths) as $path) {
            if (Storage::disk($disk)->delete($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Delete any GDPR export dossiers previously written for this creator
     * (qds:gdpr-export-creator writes creator-{id}-{ts}.json under the
     * tenant's — and legacy global — gdpr/ prefix on the exports disk).
     */
    private function deleteExportDossiers(Creator $creator): int
    {
        $disk = Storage::disk((string) config('qds.exports.disk'));
        $prefix = "creator-{$creator->id}-";
        $deleted = 0;

        foreach (["tenants/{$creator->tenant_id}/gdpr", 'gdpr'] as $dir) {
            foreach ($disk->files($dir) as $file) {
                if (str_starts_with(basename($file), $prefix) && $disk->delete($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}
