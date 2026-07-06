<?php

namespace App\Modules\Monitoring\Livewire\Review;

use App\Models\User;
use App\Modules\Monitoring\Models\ContentHashtag;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Platform\Enrichment\Review\ReviewQueue;
use App\Platform\Enrichment\Review\ReviewService;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\SentimentLabel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The DP-004 human review queue (AC-M1-002/009/010): low-confidence AI
 * outputs (mention classification, recognition, sentiment) and ambiguous
 * hashtag matches, with the original AI output, its evidence (signals),
 * and approve / correct / reject / unresolved decisions.
 *
 * Server-side authorization on every action: the page needs
 * monitoring.view; every decision re-authorizes through the model's
 * update policy (monitoring.manage) inside ReviewService — never
 * CLIENT_VIEWER (Livewire convention: authorize in mount() AND every
 * mutating action).
 */
class ReviewQueueIndex extends Component
{
    #[Url(except: '')]
    public string $kind = '';

    /** "kind:id" of the item whose decision form is open. */
    public string $actingOn = '';

    public string $reason = '';

    public string $correctionMentionType = '';

    public string $correctionBrand = '';

    public string $correctionSentiment = '';

    public ?int $correctionHashtagListId = null;

    public function mount(): void
    {
        // The queue renders all four reviewable kinds — authorize each,
        // not just mentions (server-side Livewire authorization).
        $this->authorize('viewAny', Mention::class);
        $this->authorize('viewAny', RecognitionDetection::class);
        $this->authorize('viewAny', SentimentAnalysis::class);
        $this->authorize('viewAny', ContentHashtag::class);
    }

    public function updatingKind(): void
    {
        $this->closeForm();
    }

    public function openForm(string $kind, int $id): void
    {
        $this->closeForm();
        $this->actingOn = $kind.':'.$id;
    }

    public function closeForm(): void
    {
        $this->reset(['actingOn', 'reason', 'correctionMentionType', 'correctionBrand', 'correctionSentiment', 'correctionHashtagListId']);
    }

    public function approve(string $kind, int $id, ReviewService $review): void
    {
        $this->decide($kind, $id, fn (Model $item, User $user) => $review->approve($item, $user, $this->reasonOrNull()));
    }

    public function reject(string $kind, int $id, ReviewService $review): void
    {
        $this->decide($kind, $id, fn (Model $item, User $user) => $review->reject($item, $user, $this->reasonOrNull()));
    }

    public function unresolved(string $kind, int $id, ReviewService $review): void
    {
        $this->decide($kind, $id, fn (Model $item, User $user) => $review->unresolved($item, $user, $this->reasonOrNull()));
    }

    public function correct(string $kind, int $id, ReviewService $review): void
    {
        $correction = match ($kind) {
            'mention' => ['mention_type' => $this->correctionMentionType],
            'recognition' => ['detected_brand' => $this->correctionBrand],
            'sentiment' => ['label' => $this->correctionSentiment],
            'hashtag' => ['hashtag_list_id' => $this->correctionHashtagListId],
            default => [],
        };

        $this->decide($kind, $id, fn (Model $item, User $user) => $review->correct($item, $correction, $user, $this->reasonOrNull()));
    }

    /** @param  callable(Model, User): mixed  $decision */
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
        return match ($kind) {
            'mention' => Mention::query()->findOrFail($id),
            'recognition' => RecognitionDetection::query()->findOrFail($id),
            'sentiment' => SentimentAnalysis::query()->findOrFail($id),
            'hashtag' => ContentHashtag::query()->findOrFail($id),
            default => throw new InvalidArgumentException('Unknown review kind.'),
        };
    }

    private function reasonOrNull(): ?string
    {
        return trim($this->reason) !== '' ? trim($this->reason) : null;
    }

    public function render(ReviewQueue $queue): View
    {
        return view('livewire.monitoring.review-queue-index', [
            'items' => $queue->items([
                'kind' => $this->kind !== '' ? $this->kind : null,
                'limit' => 50,
            ]),
            'counts' => $queue->counts(),
            'kinds' => ReviewQueue::KINDS,
            'mentionTypes' => MentionType::cases(),
            'sentimentLabels' => SentimentLabel::cases(),
        ]);
    }
}
