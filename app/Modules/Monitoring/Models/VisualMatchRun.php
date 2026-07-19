<?php

namespace App\Modules\Monitoring\Models;

use App\Platform\AiBudget\Priority;
use App\Shared\Enums\VisualMatchOutcome;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One visual product-match analysis run over a post's keyframes
 * (sub-project C, spec §4.4). Append-only usage: the latest run per post is
 * authoritative; history stays for calibration (sub-project E) and
 * debugging. needs_verification is sub-project D's poll flag ("verify this
 * post with the VLM") — D adds its own consumption bookkeeping. Erased
 * with the creator's content (CreatorEraser); candidates cascade at the DB.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $content_item_id
 * @property int|null $story_id
 * @property string $correlation_id
 * @property string $model_version
 * @property Priority $priority
 * @property int $frames_available
 * @property int $frames_processed
 * @property int $frames_skipped_format
 * @property int $frames_skipped_quality
 * @property int $frames_deduped
 * @property int $cache_hits
 * @property int $processing_ms
 * @property int $candidates_checked
 * @property float|null $best_score
 * @property VisualMatchOutcome $outcome
 * @property string|null $rejection_reason
 * @property array<string, mixed> $thresholds
 * @property int $embedding_calls
 * @property int $estimated_cost_micro_usd
 * @property bool $needs_verification
 */
class VisualMatchRun extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\VisualMatchRunFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'content_item_id',
        'story_id',
        'correlation_id',
        'model_version',
        'priority',
        'frames_available',
        'frames_processed',
        'frames_skipped_format',
        'frames_skipped_quality',
        'frames_deduped',
        'cache_hits',
        'processing_ms',
        'candidates_checked',
        'best_score',
        'outcome',
        'rejection_reason',
        'thresholds',
        'embedding_calls',
        'estimated_cost_micro_usd',
        'needs_verification',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'best_score' => 'float',
            'outcome' => VisualMatchOutcome::class,
            'thresholds' => 'array',
            'needs_verification' => 'boolean',
            'created_at' => 'immutable_datetime',
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

    /** @return HasMany<VisualMatchCandidate, $this> */
    public function candidates(): HasMany
    {
        return $this->hasMany(VisualMatchCandidate::class);
    }
}
