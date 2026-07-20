<?php

namespace App\Modules\Monitoring\Models;

use App\Platform\AiBudget\Priority;
use App\Shared\Enums\VlmRunOutcome;
use App\Shared\Enums\VlmTriggerReason;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One VLM verification attempt-set over an escalated post (sub-project D,
 * spec §8.1). Append-only per verification: created 'pending' before the
 * first billed call (attempts is the crash-safe billing ledger, committed
 * BEFORE each provider call), finalized exactly once to a terminal
 * outcome, never re-opened — a re-verification is a NEW row under a new
 * model_version (the partial unique re-opens old anchors) or a new anchor
 * run. visual_match_run_id NULL from birth = a DEF-021 'unverifiable'
 * discovery row, never sent to Gemini. Erased with the creator's content
 * (CreatorEraser); verdicts cascade at the DB.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $content_item_id
 * @property int|null $story_id
 * @property int|null $visual_match_run_id
 * @property string $correlation_id
 * @property string $model_version
 * @property VlmTriggerReason $trigger_reason
 * @property Priority $priority
 * @property int $frames_sent
 * @property int|null $prompt_tokens
 * @property int|null $output_tokens
 * @property int|null $thinking_tokens
 * @property int $attempts billed calls — committed BEFORE each provider call
 * @property VlmRunOutcome $outcome
 * @property string|null $rejection_reason
 * @property array<string, mixed> $thresholds snapshot {auto, review, margin}
 * @property int $latency_ms
 * @property int $estimated_cost_micro_usd
 */
class VlmVerificationRun extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\VlmVerificationRunFactory> */
    use HasFactory;

    protected $fillable = [
        'content_item_id',
        'story_id',
        'visual_match_run_id',
        'correlation_id',
        'model_version',
        'trigger_reason',
        'priority',
        'frames_sent',
        'prompt_tokens',
        'output_tokens',
        'thinking_tokens',
        'attempts',
        'outcome',
        'rejection_reason',
        'thresholds',
        'latency_ms',
        'estimated_cost_micro_usd',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trigger_reason' => VlmTriggerReason::class,
            'priority' => Priority::class,
            'outcome' => VlmRunOutcome::class,
            'thresholds' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return BelongsTo<Story, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /**
     * The anchor C run this verification consumed — null for DEF-021
     * discovery rows and after an anchor delete (column-scoped SET NULL).
     *
     * @return BelongsTo<VisualMatchRun, $this>
     */
    public function visualMatchRun(): BelongsTo
    {
        return $this->belongsTo(VisualMatchRun::class);
    }

    /** @return HasMany<VlmCandidateVerdict, $this> */
    public function verdicts(): HasMany
    {
        return $this->hasMany(VlmCandidateVerdict::class);
    }
}
