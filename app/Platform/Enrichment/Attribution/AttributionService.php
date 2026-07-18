<?php

namespace App\Platform\Enrichment\Attribution;

use App\Modules\CRM\Models\Brand;
use App\Modules\Monitoring\Contracts\ContentMatchFeedback;
use App\Modules\Monitoring\Models\ContentHashtag;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Contracts\SeedingEvidenceSource;
use App\Platform\Enrichment\Support\HumanPrecedence;
use App\Platform\Enrichment\TextSignals\ContextualCueDetector;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Seeded-attribution stage (REQ-M1-002): assembles the evidence bundle for
 * one ContentItem/Story, runs the pure MentionClassifier, and upserts the
 * Mention per active CREATOR MonitoredSubject — never overwriting a
 * human-reviewed/corrected classification (DP-004).
 *
 * The Mention's Provenance is the underlying content's provenance: the
 * classification derives from that externally-sourced record (DP-002).
 */
class AttributionService
{
    public function __construct(
        private readonly MentionClassifier $classifier,
        private readonly SeedingEvidenceSource $seedingEvidence,
        private readonly ContentMatchFeedback $matchFeedback,
    ) {}

    /** @return list<Mention> the mentions written (or left untouched by precedence) */
    public function enrich(ContentItem|Story $target): array
    {
        $subjects = $this->subjectsFor($target);

        if ($subjects === []) {
            return [];
        }

        $evidence = $this->buildEvidence($target);

        $result = $this->classifier->classify($evidence);

        $targetKey = $target instanceof ContentItem ? 'content_item_id' : 'story_id';

        // No evidence now supports any classification. If an AI-written
        // mention already exists (e.g. its sole supporting recognition was
        // human-rejected), retract it to UNKNOWN/LOW so it re-enters the
        // review queue rather than persisting as a stale, invisible
        // SEEDED/LIKELY_ORGANIC claim (DP-003, DP-004).
        if ($result === null) {
            $retracted = [];

            foreach ($subjects as $subject) {
                $mention = $this->retractStaleMention($subject, $target, $targetKey);

                if ($mention !== null) {
                    $retracted[] = $mention;
                }
            }

            return $retracted;
        }

        $mentions = [];

        foreach ($subjects as $subject) {
            $mentions[] = $this->upsertMention($subject, $target, $targetKey, $result);
        }

        return $mentions;
    }

    private function upsertMention(
        MonitoredSubject $subject,
        ContentItem|Story $target,
        string $targetKey,
        ClassificationResult $result,
    ): Mention {
        $attributes = [
            'monitored_subject_id' => $subject->id,
            $targetKey => $target->id,
        ];

        $mention = Mention::query()->firstOrNew($attributes);

        if ($mention->exists && ! HumanPrecedence::allowsAiUpdate($mention->classification)) {
            // A human decided; later AI runs never overwrite (DP-004).
            return $mention;
        }

        $this->applyClassification($mention, $result, $target->provenance);

        try {
            $mention->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent enrichment pass inserted the row first (the
            // partial unique index is the backstop). Re-load and re-apply,
            // honouring human precedence on the winning row (DP-004).
            $mention = Mention::query()->where($attributes)->firstOrFail();

            if (HumanPrecedence::allowsAiUpdate($mention->classification)) {
                $this->applyClassification($mention, $result, $target->provenance);
                $mention->save();
            }
        }

        return $mention;
    }

    private function applyClassification(Mention $mention, ClassificationResult $result, Provenance $provenance): void
    {
        $mention->fill([
            'mention_type' => $result->mentionType,
            'classification' => new ConfidenceAssessment(
                value: $result->mentionType->value,
                confidenceLevel: $result->confidenceLevel,
                signals: $result->signals,
                verificationStatus: VerificationStatus::AiAssessed,
            ),
            'provenance' => $provenance,
        ]);
    }

    /** Downgrade a now-unsupported AI-owned mention; null if there is nothing to retract. */
    private function retractStaleMention(MonitoredSubject $subject, ContentItem|Story $target, string $targetKey): ?Mention
    {
        $mention = Mention::query()
            ->where('monitored_subject_id', $subject->id)
            ->where($targetKey, $target->id)
            ->first();

        if ($mention === null || ! HumanPrecedence::allowsAiUpdate($mention->classification)) {
            return $mention === null ? null : $mention;
        }

        // Already retracted — leave the existing envelope untouched.
        if ($mention->mention_type === MentionType::Unknown
            && $mention->classification->confidenceLevel === ConfidenceLevel::Low
            && in_array('evidence-retracted', $mention->classification->signals, true)) {
            return $mention;
        }

        $campaignId = $mention->campaign_id;

        $mention->fill([
            'mention_type' => MentionType::Unknown,
            'classification' => new ConfidenceAssessment(
                value: MentionType::Unknown->value,
                confidenceLevel: ConfidenceLevel::Low,
                signals: ['evidence-retracted', 'no-supporting-evidence'],
                verificationStatus: VerificationStatus::AiAssessed,
            ),
            'provenance' => $target->provenance,
        ]);

        $mention->save();

        // A now-UNKNOWN mention must drop its campaign attribution, or it keeps
        // over-counting the campaign/brand mention totals (M28). Route through
        // the single sanctioned write path (nulls campaign_id + audits) rather
        // than force-writing it here.
        if ($campaignId !== null && $target instanceof ContentItem) {
            $this->matchFeedback->deny($target, $campaignId);
            $mention->refresh();
        }

        return $mention;
    }

    /** @return list<MonitoredSubject> */
    private function subjectsFor(ContentItem|Story $target): array
    {
        $creatorId = $target->platformAccount?->creator_id;

        if ($creatorId === null) {
            return [];
        }

        return MonitoredSubject::query()
            ->where('subject_type', MonitoredSubjectType::Creator)
            ->where('creator_id', $creatorId)
            ->where('active', true)
            ->get()
            ->filter(fn (MonitoredSubject $subject): bool => collect($subject->platforms)->contains($target->platform))
            ->values()
            ->all();
    }

    private function buildEvidence(ContentItem|Story $target): EvidenceBundle
    {
        // Kill switch (Tier 0 free-signal detection, sub-project A): OFF
        // reproduces the legacy brand-level doctrine exactly (precision
        // gate skipped, no paid label, no contextual cues, no product
        // doctrine); ON enables the full product-aware behaviour.
        $enabled = (bool) config('qds.enrichment.text_signals.enabled');

        $recognitions = [];

        $detectionQuery = RecognitionDetection::query();
        $detectionQuery = $target instanceof ContentItem
            ? $detectionQuery->where('content_item_id', $target->id)
            : $detectionQuery->where('story_id', $target->id);

        foreach ($detectionQuery->get() as $detection) {
            $assessment = $detection->assessment;

            // Human-rejected detections carry no evidential weight.
            if ($assessment->value === null || in_array('human-rejected', $assessment->signals, true)) {
                continue;
            }

            if ($detection->detected_brand === null) {
                continue;
            }

            // Precision gate: an UNMATCHED logo (brand not in the lexicon) or a
            // low-confidence logo carries no attribution relevance. Gated
            // behind the kill switch — OFF reproduces the legacy behaviour
            // where such detections still carried evidential weight.
            if ($enabled
                && $detection->recognition_type === RecognitionType::Logo
                && (in_array('brand-lexicon:unmatched', $assessment->signals, true)
                    || $assessment->confidenceLevel === ConfidenceLevel::Low
                    || $assessment->confidenceLevel === ConfidenceLevel::Unknown)) {
                continue;
            }

            $recognitions[] = [
                'type' => $detection->recognition_type->value,
                'brand' => $detection->detected_brand,
                'level' => $assessment->confidenceLevel,
                'productId' => $detection->product_id,
                'product' => $detection->detected_product,
            ];
        }

        [$hashtagMatches, $ambiguous] = $target instanceof ContentItem
            ? $this->hashtagEvidence($target)
            : [[], []];

        return new EvidenceBundle(
            recognitions: $recognitions,
            hashtagMatches: $hashtagMatches,
            ambiguousHashtags: $ambiguous,
            shipments: $this->seedingEvidence->forTarget($target),
            paidPartnershipLabel: $enabled ? ($target instanceof ContentItem ? $target->branded_content_label : null) : false,
            contextualCues: $enabled && $target instanceof ContentItem
                ? app(ContextualCueDetector::class)->detect($target->caption)
                : [],
            publishedAt: $this->publicationDate($target),
            productDoctrine: $enabled,
        );
    }

    /**
     * @return array{0: list<array{original: string, scope: string, campaign_id: int|null, brand_id: int|null, brand_name: string|null, product_label: string|null}>, 1: list<string>}
     */
    private function hashtagEvidence(ContentItem $target): array
    {
        $rows = ContentHashtag::query()
            ->where('content_item_id', $target->id)
            ->get();

        $matches = [];
        $ambiguous = [];

        $brandNames = Brand::query()
            ->whereIn('id', $rows->flatMap(
                fn (ContentHashtag $row): array => array_values(array_filter(array_map(
                    static fn (array $m): ?int => $m['brand_id'],
                    $row->matches ?? [],
                ))),
            )->unique()->values()->all())
            ->pluck('name', 'id');

        foreach ($rows as $row) {
            $rowMatches = $row->matches ?? [];

            if ($rowMatches === []) {
                continue;
            }

            if ($row->needsHumanReview()) {
                $ambiguous[] = $row->original;

                continue;
            }

            // A human REJECTED the ambiguous match ("none of these lists
            // apply"): it is resolved with no list id. Such a tag carries no
            // attribution evidence — never re-promote its conflicting
            // matches, which would invert the human decision (DP-004).
            if ($row->resolved_at !== null && $row->resolved_hashtag_list_id === null) {
                continue;
            }

            // A human resolution narrows an ambiguous tag to one list entry.
            if ($row->resolved_hashtag_list_id !== null) {
                $rowMatches = array_values(array_filter(
                    $rowMatches,
                    fn (array $m): bool => $m['hashtag_list_id'] === $row->resolved_hashtag_list_id,
                ));
            }

            foreach ($rowMatches as $match) {
                $brandId = $match['brand_id'];

                $matches[] = [
                    'original' => $row->original,
                    'scope' => $match['scope'],
                    'campaign_id' => $match['campaign_id'],
                    'brand_id' => $brandId,
                    'brand_name' => $brandId !== null ? $brandNames->get($brandId) : null,
                    'product_label' => $match['product_label'],
                ];
            }
        }

        return [$matches, $ambiguous];
    }

    private function publicationDate(ContentItem|Story $target): ?CarbonImmutable
    {
        if ($target instanceof ContentItem) {
            return $target->published_at;
        }

        // Stories carry no published timestamp; the archival capture time
        // is the closest observed publication anchor (REQ-M1-004).
        return $target->captured_at;
    }
}
