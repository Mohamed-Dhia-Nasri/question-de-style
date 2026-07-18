<?php

namespace App\Platform\Enrichment\Review;

use App\Models\User;
use App\Modules\Monitoring\Models\ContentHashtag;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\ReviewAction;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Platform\Enrichment\Support\ReviewDecision;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\SentimentLabel;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Reusable human review workflow (DP-004) over every reviewable AI output:
 * mentions (seeded attribution / campaign matching evidence), recognition
 * detections, sentiment analyses, and ambiguous hashtag matches.
 *
 * Every decision:
 *  - is authorized server-side (the model's update policy, i.e.
 *    monitoring.manage — never CLIENT_VIEWER);
 *  - snapshots the ORIGINAL AI output into the append-only review_actions
 *    history (corrections are stored, never silently overwritten);
 *  - moves the envelope along ENUM-VerificationStatus: APPROVE →
 *    HUMAN_REVIEWED, CORRECT/REJECT → HUMAN_CORRECTED, UNRESOLVED keeps
 *    AI_ASSESSED (the item stays queued);
 *  - is audit-logged with reviewer identity and timestamp.
 *
 * Once corrected, later AI runs never overwrite the value (HumanPrecedence
 * in every writer).
 */
class ReviewService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function approve(Model $reviewable, User $reviewer, ?string $reason = null): ReviewAction
    {
        $this->authorize($reviewable, $reviewer);

        $original = $this->snapshot($reviewable);

        if ($reviewable instanceof ContentHashtag) {
            throw new InvalidArgumentException(
                'An ambiguous hashtag match cannot be approved as-is — correct it to one list entry or reject it.'
            );
        }

        $this->moveEnvelope($reviewable, VerificationStatus::HumanReviewed, null, ['human-approved']);

        return $this->record($reviewable, ReviewDecision::Approve, $original, null, $reason, $reviewer);
    }

    /** @param array<string, mixed> $correction */
    public function correct(Model $reviewable, array $correction, User $reviewer, ?string $reason = null): ReviewAction
    {
        $this->authorize($reviewable, $reviewer);

        $original = $this->snapshot($reviewable);

        match (true) {
            $reviewable instanceof Mention => $this->correctMention($reviewable, $correction, $reason),
            $reviewable instanceof RecognitionDetection => $this->correctRecognition($reviewable, $correction),
            $reviewable instanceof SentimentAnalysis => $this->correctSentiment($reviewable, $correction),
            $reviewable instanceof ContentHashtag => $this->resolveHashtag($reviewable, $correction, $reviewer),
            default => throw new InvalidArgumentException('Unsupported reviewable type: '.$reviewable::class),
        };

        return $this->record($reviewable, ReviewDecision::Correct, $original, $correction, $reason, $reviewer);
    }

    public function reject(Model $reviewable, User $reviewer, ?string $reason = null): ReviewAction
    {
        $this->authorize($reviewable, $reviewer);

        $original = $this->snapshot($reviewable);

        match (true) {
            // A rejected mention is "no real brand reference": the honest
            // classification is UNKNOWN — deletion of AI outputs is never
            // allowed (policy), the record and its history remain.
            $reviewable instanceof Mention => $this->applyMention($reviewable, MentionType::Unknown, ['human-rejected']),
            $reviewable instanceof RecognitionDetection => $this->moveEnvelope($reviewable, VerificationStatus::HumanCorrected, null, ['human-rejected'], nullValue: true),
            $reviewable instanceof SentimentAnalysis => $this->rejectSentiment($reviewable),
            $reviewable instanceof ContentHashtag => $this->resolveHashtag($reviewable, ['hashtag_list_id' => null], $reviewer, allowClear: true),
            default => throw new InvalidArgumentException('Unsupported reviewable type: '.$reviewable::class),
        };

        return $this->record($reviewable, ReviewDecision::Reject, $original, null, $reason, $reviewer);
    }

    /** Leave the item in the queue, recording that a reviewer looked at it. */
    public function unresolved(Model $reviewable, User $reviewer, ?string $reason = null): ReviewAction
    {
        $this->authorize($reviewable, $reviewer);

        return $this->record($reviewable, ReviewDecision::Unresolved, $this->snapshot($reviewable), null, $reason, $reviewer);
    }

    /**
     * Full correction history for one reviewable (evidence display).
     *
     * @return Collection<int, ReviewAction>
     */
    public function history(Model $reviewable): Collection
    {
        return ReviewAction::query()
            ->where('reviewable_type', $reviewable->getMorphClass())
            ->where('reviewable_id', $reviewable->getKey())
            ->orderByDesc('id')
            ->get();
    }

    private function authorize(Model $reviewable, User $reviewer): void
    {
        Gate::forUser($reviewer)->authorize('update', $reviewable);
    }

    /** @param array<string, mixed> $correction */
    private function correctMention(Mention $mention, array $correction, ?string $reason): void
    {
        $type = MentionType::from((string) ($correction['mention_type'] ?? ''));

        $signals = ['human-correction'];

        // PAID/SEEDED still require a proving record — for a human decision
        // that record is the manual confirmation itself, which MUST name
        // its basis (AC-M1-003: the proving signal is recorded).
        if (in_array($type, [MentionType::Paid, MentionType::Seeded], true)) {
            if ($reason === null || trim($reason) === '') {
                throw new InvalidArgumentException(
                    'Correcting a mention to PAID/SEEDED requires the proving record in the reason (manual confirmation).'
                );
            }

            $signals[] = 'manual-confirmation:'.trim($reason);
        }

        $this->applyMention($mention, $type, $signals);
    }

    /** @param list<string> $extraSignals */
    private function applyMention(Mention $mention, MentionType $type, array $extraSignals): void
    {
        $existing = $mention->classification;

        $mention->mention_type = $type;
        $mention->classification = new ConfidenceAssessment(
            value: $type->value,
            confidenceLevel: $existing->confidenceLevel,
            signals: [...$existing->signals, ...$extraSignals],
            verificationStatus: VerificationStatus::HumanCorrected,
        );
        $mention->save();
    }

    /** @param array<string, mixed> $correction */
    private function correctRecognition(RecognitionDetection $detection, array $correction): void
    {
        $brand = $correction['detected_brand'] ?? null;

        if (! is_string($brand) || trim($brand) === '') {
            throw new InvalidArgumentException('Correcting a recognition requires a detected_brand value.');
        }

        $detection->detected_brand = trim($brand);
        $this->moveEnvelope($detection, VerificationStatus::HumanCorrected, trim($brand), ['human-correction']);
    }

    /** @param array<string, mixed> $correction */
    private function correctSentiment(SentimentAnalysis $analysis, array $correction): void
    {
        $label = SentimentLabel::from((string) ($correction['label'] ?? ''));

        $analysis->label = $label;
        $this->moveEnvelope($analysis, VerificationStatus::HumanCorrected, $label->value, ['human-correction']);
    }

    private function rejectSentiment(SentimentAnalysis $analysis): void
    {
        $analysis->label = SentimentLabel::Unknown;
        $this->moveEnvelope($analysis, VerificationStatus::HumanCorrected, SentimentLabel::Unknown->value, ['human-rejected']);
    }

    /** @param array<string, mixed> $correction */
    private function resolveHashtag(ContentHashtag $hashtag, array $correction, User $reviewer, bool $allowClear = false): void
    {
        $listId = $correction['hashtag_list_id'] ?? null;

        // A CORRECT decision must pick one of the ambiguous entries; only the
        // explicit reject() path may clear the match to nothing (M16).
        if ($listId === null && ! $allowClear) {
            throw new InvalidArgumentException('Resolving an ambiguous hashtag requires choosing one of its list entries — use reject to record no match.');
        }

        if ($listId !== null) {
            $valid = array_map(
                static fn (array $m): int => $m['hashtag_list_id'],
                $hashtag->matches ?? [],
            );

            if (! in_array((int) $listId, $valid, true)) {
                throw new InvalidArgumentException('The chosen hashtag list entry is not one of the ambiguous matches.');
            }
        }

        $hashtag->fill([
            'resolved_hashtag_list_id' => $listId !== null ? (int) $listId : null,
            'resolved_by' => $reviewer->id,
            'resolved_at' => CarbonImmutable::now(),
            'is_ambiguous' => false,
        ]);
        $hashtag->save();
    }

    /**
     * Move an envelope along the verification lifecycle, preserving the
     * inferred value unless explicitly corrected or nulled (rejection).
     *
     * @param  list<string>  $extraSignals
     */
    private function moveEnvelope(
        Model $reviewable,
        VerificationStatus $status,
        mixed $correctedValue,
        array $extraSignals,
        bool $nullValue = false,
    ): void {
        $attribute = $reviewable instanceof Mention ? 'classification' : 'assessment';

        /** @var ConfidenceAssessment $existing */
        $existing = $reviewable->getAttribute($attribute);

        $reviewable->setAttribute($attribute, new ConfidenceAssessment(
            value: $nullValue ? null : ($correctedValue ?? $existing->value),
            confidenceLevel: $existing->confidenceLevel,
            signals: [...$existing->signals, ...$extraSignals],
            verificationStatus: $status,
        ));

        $reviewable->save();
    }

    /** @return array<string, mixed> */
    private function snapshot(Model $reviewable): array
    {
        return [
            'type' => $reviewable->getMorphClass(),
            'id' => $reviewable->getKey(),
            // Raw DB values (envelopes as their persisted JSON) — the exact
            // original AI output, uncast and unmodified.
            'attributes' => $reviewable->getRawOriginal(),
        ];
    }

    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>|null  $correction
     */
    private function record(
        Model $reviewable,
        ReviewDecision $decision,
        array $original,
        ?array $correction,
        ?string $reason,
        User $reviewer,
    ): ReviewAction {
        $action = ReviewAction::query()->create([
            'reviewable_type' => $reviewable->getMorphClass(),
            'reviewable_id' => $reviewable->getKey(),
            'action' => $decision,
            'original' => $original,
            'correction' => $correction,
            'reason' => $reason,
            'user_id' => $reviewer->id,
            'actor_id' => $reviewer->id,
        ]);

        $this->audit->record('enrichment.review.'.strtolower($decision->value), $reviewable, [
            'decision' => $decision->value,
            'reason' => $reason,
            'review_action_id' => $action->id,
        ]);

        return $action;
    }
}
