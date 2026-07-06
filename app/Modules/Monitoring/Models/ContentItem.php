<?php

namespace App\Modules\Monitoring\Models;

use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Enrichment\Models\EnrichmentRun;
use App\Shared\Casts\AsValueObject;
use App\Shared\Casts\AsValueObjectCollection;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Database\Factories\ContentItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ENT-ContentItem — a durable public post/reel/video
 * (docs/30-data-model/00-data-model.md#ent-contentitem, REQ-M1-003).
 *
 * Write-owner: Module 1 Monitoring (ownership matrix); written by the
 * ingestion pipeline. Externally sourced → mandatory Provenance envelope
 * (DP-002). Stories are NEVER ContentItems — ENUM-ContentType deliberately
 * has no STORY value (rule F8); ephemeral stories are the separate
 * ENT-Story entity.
 *
 * @property int $id
 * @property int $platform_account_id
 * @property Platform $platform
 * @property ContentType $content_type
 * @property string|null $external_id
 * @property string|null $caption
 * @property array<int, string>|null $media_urls
 * @property CarbonImmutable|null $published_at
 * @property list<MetricValue>|null $public_metrics
 * @property Provenance $provenance
 * @property array<int, string>|null $human_overrides
 */
class ContentItem extends Model
{
    /** @use HasFactory<ContentItemFactory> */
    use HasFactory;

    protected $fillable = [
        'platform_account_id',
        'platform',
        'content_type',
        'external_id',
        'caption',
        'media_urls',
        'published_at',
        'public_metrics',
        'provenance',
        'human_overrides',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'content_type' => ContentType::class,
            'media_urls' => 'array',
            'published_at' => 'immutable_datetime',
            'public_metrics' => AsValueObjectCollection::class.':'.MetricValue::class,
            'provenance' => AsValueObject::class.':'.Provenance::class,
            'human_overrides' => 'array',
        ];
    }

    /** @return BelongsTo<PlatformAccount, $this> */
    public function platformAccount(): BelongsTo
    {
        return $this->belongsTo(PlatformAccount::class);
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return HasMany<Mention, $this> */
    public function mentions(): HasMany
    {
        return $this->hasMany(Mention::class);
    }

    /** @return HasMany<RecognitionDetection, $this> */
    public function recognitionDetections(): HasMany
    {
        return $this->hasMany(RecognitionDetection::class);
    }

    /** @return HasMany<SentimentAnalysis, $this> */
    public function sentimentAnalyses(): HasMany
    {
        return $this->hasMany(SentimentAnalysis::class);
    }

    /** @return HasMany<MetricSnapshot, $this> */
    public function metricSnapshots(): HasMany
    {
        return $this->hasMany(MetricSnapshot::class);
    }

    /** @return HasMany<ContentHashtag, $this> */
    public function contentHashtags(): HasMany
    {
        return $this->hasMany(ContentHashtag::class);
    }

    /** @return HasMany<EmvResult, $this> */
    public function emvResults(): HasMany
    {
        return $this->hasMany(EmvResult::class);
    }

    /** @return HasMany<EnrichmentRun, $this> */
    public function enrichmentRuns(): HasMany
    {
        return $this->hasMany(EnrichmentRun::class);
    }
}
