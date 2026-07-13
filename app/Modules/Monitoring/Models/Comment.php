<?php

namespace App\Modules\Monitoring\Models;

use App\Shared\Casts\AsValueObject;
use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ENT-Comment — a public comment on a ContentItem
 * (docs/30-data-model/00-data-model.md#ent-comment).
 *
 * Write-owner: Module 1 Monitoring (ownership matrix). SCHEMA ONLY IN V1:
 * comment collection (REQ-M1-010) is DEFERRED (DEF-005 / ADR-0009) — no
 * ingestion writes this table and no comment feature is exposed; surfaces
 * render comment-derived data as "unavailable".
 *
 * author_handle and text are third-party personal data (DP-005) and are
 * encrypted at rest via Laravel encrypted casts.
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 */
class Comment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    protected $fillable = [
        'content_item_id',
        'parent_comment_id',
        'author_handle',
        'text',
        'like_count',
        'posted_at',
        'provenance',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'author_handle' => 'encrypted',
            'text' => 'encrypted',
            'like_count' => AsValueObject::class.':'.MetricValue::class,
            'posted_at' => 'immutable_datetime',
            'provenance' => AsValueObject::class.':'.Provenance::class,
        ];
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return BelongsTo<Comment, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_comment_id');
    }

    /** @return HasMany<Comment, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_comment_id');
    }

    /** @return HasMany<SentimentAnalysis, $this> */
    public function sentimentAnalyses(): HasMany
    {
        return $this->hasMany(SentimentAnalysis::class);
    }
}
