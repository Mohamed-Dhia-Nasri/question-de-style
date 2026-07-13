<?php

namespace App\Modules\Monitoring\Models;

use App\Shared\Casts\AsValueObject;
use App\Shared\Enums\SentimentLabel;
use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Database\Factories\SentimentAnalysisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ENT-SentimentAnalysis — sentiment + context for a ContentItem or Comment
 * (docs/30-data-model/00-data-model.md#ent-sentimentanalysis, REQ-M1-009).
 *
 * Write-owner: Module 1 Monitoring (ownership matrix). Inferred → mandatory
 * ConfidenceAssessment (DP-003); internal AI output, so the canonical shape
 * carries no Provenance envelope. Manual correction feeds the review loop
 * (DP-004). Comment analysis is deferred (DEF-005): v1 sentiment runs on
 * captions/transcripts only, so comment_id stays unused.
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $content_item_id
 * @property int|null $comment_id
 * @property SentimentLabel $label
 * @property string|null $context_summary
 * @property ConfidenceAssessment $assessment
 */
class SentimentAnalysis extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SentimentAnalysisFactory> */
    use HasFactory;

    protected $fillable = [
        'content_item_id',
        'comment_id',
        'label',
        'context_summary',
        'assessment',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'label' => SentimentLabel::class,
            'assessment' => AsValueObject::class.':'.ConfidenceAssessment::class,
        ];
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return BelongsTo<Comment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }
}
