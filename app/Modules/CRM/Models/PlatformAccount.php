<?php

namespace App\Modules\CRM\Models;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\Story;
use App\Shared\Casts\AsValueObject;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Database\Factories\PlatformAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ENT-PlatformAccount — one per-platform presence of a Creator
 * (docs/30-data-model/00-data-model.md#ent-platformaccount).
 *
 * Write-owner: Module 3 CRM (ownership matrix). Module 1 reads it as the
 * author anchor for content, stories, and metric snapshots. Externally
 * sourced → mandatory Provenance envelope (DP-002); (platform, handle) is
 * the unique external platform identifier.
 *
 * @property int $id
 * @property int|null $creator_id
 * @property Platform $platform
 * @property string $handle
 * @property string|null $bio
 * @property array<int, string>|null $external_links
 * @property MetricValue|null $follower_count
 * @property Provenance $provenance
 */
class PlatformAccount extends Model
{
    /** @use HasFactory<PlatformAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'platform',
        'handle',
        'bio',
        'external_links',
        'follower_count',
        'provenance',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'external_links' => 'array',
            'follower_count' => AsValueObject::class.':'.MetricValue::class,
            'provenance' => AsValueObject::class.':'.Provenance::class,
        ];
    }

    /** @return BelongsTo<Creator, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /** @return HasMany<ContentItem, $this> */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    /** @return HasMany<Story, $this> */
    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }

    /** @return HasMany<MetricSnapshot, $this> */
    public function metricSnapshots(): HasMany
    {
        return $this->hasMany(MetricSnapshot::class);
    }
}
