<?php

namespace App\Modules\Monitoring\Models;

use App\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ContentHashtagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hashtag extracted from a ContentItem caption: the original form is
 * preserved verbatim, the normalized form (Unicode NFKC + case fold) drives
 * matching against configured hashtag lists. When the same hashtag matches
 * more than one campaign/brand/product list, the row is flagged ambiguous
 * and routes to human review (DP-004); a human resolution is never
 * overwritten by re-extraction.
 *
 * FLAGGED DEVIATION: not a canonical ENT-* — awaiting a data-model doc
 * amendment (see the create_enrichment_tables migration).
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $content_item_id
 * @property string $original
 * @property string $normalized
 * @property int $first_position
 * @property int $occurrences
 * @property list<array{hashtag_list_id: int, scope: string, campaign_id: int|null, brand_id: int|null, product_label: string|null}>|null $matches
 * @property bool $is_ambiguous
 * @property int|null $resolved_hashtag_list_id
 * @property int|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 */
class ContentHashtag extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ContentHashtagFactory> */
    use HasFactory;

    protected $fillable = [
        'content_item_id',
        'original',
        'normalized',
        'first_position',
        'occurrences',
        'matches',
        'is_ambiguous',
        'resolved_hashtag_list_id',
        'resolved_by',
        'resolved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'matches' => 'array',
            'is_ambiguous' => 'boolean',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return BelongsTo<HashtagList, $this> */
    public function resolvedHashtagList(): BelongsTo
    {
        return $this->belongsTo(HashtagList::class, 'resolved_hashtag_list_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Whether an ambiguous match still awaits a human decision (DP-004). */
    public function needsHumanReview(): bool
    {
        return $this->is_ambiguous && $this->resolved_at === null;
    }
}
