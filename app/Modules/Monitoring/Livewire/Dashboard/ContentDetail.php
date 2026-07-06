<?php

namespace App\Modules\Monitoring\Livewire\Dashboard;

use App\Models\User;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\ReviewAction;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Platform\Enrichment\Metrics\DerivedMetricsService;
use App\Platform\Enrichment\Review\ReviewService;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\SentimentLabel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Component;

/**
 * Content Detail (REQ-M1-003/005/006/012): authorized media preview,
 * caption/platform/publication data, latest PUBLIC metrics with tier
 * badges, DERIVED rates (never PUBLIC — DP-001), estimated reach
 * (Unavailable until a canonical method exists; CONFIRMED reach is
 * DEF-003), EMV with model disclosure (AC-M1-011), and the content's
 * mention / recognition / sentiment assessments with confidence,
 * verification, provenance, and authorized review actions (DP-004).
 *
 * Review decisions re-authorize server-side through ReviewService (model
 * update policies — monitoring.manage); the page itself needs
 * monitoring.view. Never CLIENT_VIEWER.
 */
class ContentDetail extends Component
{
    public ContentItem $contentItem;

    /** "kind:id" of the assessment whose correction form is open. */
    public string $actingOn = '';

    public string $reason = '';

    public string $correctionMentionType = '';

    public string $correctionSentiment = '';

    public function mount(ContentItem $contentItem): void
    {
        $this->authorize('view', $contentItem);

        $this->contentItem = $contentItem->load('platformAccount.creator');
    }

    public function openForm(string $kind, int $id): void
    {
        $this->closeForm();
        $this->actingOn = $kind.':'.$id;
    }

    public function closeForm(): void
    {
        $this->reset(['actingOn', 'reason', 'correctionMentionType', 'correctionSentiment']);
    }

    public function approve(string $kind, int $id, ReviewService $review): void
    {
        $this->decide($kind, $id, fn (Model $item, User $user) => $review->approve($item, $user, $this->reasonOrNull()));
    }

    public function reject(string $kind, int $id, ReviewService $review): void
    {
        $this->decide($kind, $id, fn (Model $item, User $user) => $review->reject($item, $user, $this->reasonOrNull()));
    }

    public function correct(string $kind, int $id, ReviewService $review): void
    {
        $correction = match ($kind) {
            'mention' => ['mention_type' => $this->correctionMentionType],
            'sentiment' => ['label' => $this->correctionSentiment],
            default => [],
        };

        $this->decide($kind, $id, fn (Model $item, User $user) => $review->correct($item, $correction, $user, $this->reasonOrNull()));
    }

    /** @param callable(Model, User): mixed $decision */
    private function decide(string $kind, int $id, callable $decision): void
    {
        $item = $this->resolve($kind, $id);

        /** @var User $user */
        $user = Auth::user();

        try {
            $decision($item, $user);
        } catch (InvalidArgumentException|\ValueError $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        $this->closeForm();
        $this->dispatch('notify', type: 'success', message: 'Review decision recorded.');
    }

    private function resolve(string $kind, int $id): Model
    {
        $item = match ($kind) {
            'mention' => Mention::query()->findOrFail($id),
            'sentiment' => SentimentAnalysis::query()->findOrFail($id),
            default => throw new InvalidArgumentException('Unknown review kind.'),
        };

        // Server-side ownership check: only this content's assessments.
        abort_unless((int) $item->getAttribute('content_item_id') === $this->contentItem->id, 404);

        return $item;
    }

    private function reasonOrNull(): ?string
    {
        return trim($this->reason) !== '' ? trim($this->reason) : null;
    }

    public function render(DerivedMetricsService $derived): View
    {
        $content = $this->contentItem->load([
            'mentions.monitoredSubject',
            'mentions.campaign',
            'recognitionDetections',
            'sentimentAnalyses',
            'emvResults' => fn ($q) => $q->orderByDesc('calculated_at')->limit(1),
            'emvResults.configuration',
        ]);

        $latestSnapshot = $content->metricSnapshots()
            ->orderByDesc('captured_at')
            ->first();

        $followerCount = $content->platformAccount?->follower_count;

        $reviewHistory = ReviewAction::query()
            ->where(function ($q) use ($content) {
                $q->where(fn ($m) => $m
                    ->where('reviewable_type', (new Mention)->getMorphClass())
                    ->whereIn('reviewable_id', $content->mentions->pluck('id')))
                    ->orWhere(fn ($s) => $s
                        ->where('reviewable_type', (new SentimentAnalysis)->getMorphClass())
                        ->whereIn('reviewable_id', $content->sentimentAnalyses->pluck('id')));
            })
            ->with('user')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return view('livewire.monitoring.content-detail', [
            'content' => $content,
            'latestSnapshot' => $latestSnapshot,
            'engagementRate' => $derived->engagementRate($content, $followerCount),
            'viewRate' => $derived->viewRate($content, $followerCount),
            'commentRate' => $derived->commentRate($content, $followerCount),
            'engagementBase' => (string) config('qds.enrichment.metrics.engagement_base'),
            'latestEmv' => $content->emvResults->first(),
            'reviewHistory' => $reviewHistory,
            'mentionTypes' => MentionType::cases(),
            'sentimentLabels' => SentimentLabel::cases(),
        ]);
    }
}
