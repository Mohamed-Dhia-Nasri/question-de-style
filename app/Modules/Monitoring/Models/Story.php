<?php

namespace App\Modules\Monitoring\Models;

use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Enrichment\Models\EnrichmentRun;
use App\Shared\Casts\AsValueObject;
use App\Shared\Casts\AsValueObjectCollection;
use App\Shared\Enums\Platform;
use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Database\Factories\StoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ENT-Story — ephemeral story content archived before platform expiry
 * (docs/30-data-model/00-data-model.md#ent-story, REQ-M1-004).
 *
 * Write-owner: Module 1 Monitoring (ownership matrix). Externally sourced →
 * mandatory Provenance envelope (DP-002). A story is always this entity and
 * never a ContentItem (STORY is not an ENUM-ContentType value).
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $platform_account_id
 * @property Platform $platform
 * @property string|null $external_id
 * @property string|null $media_url
 * @property CarbonImmutable|null $media_pruned_at
 * @property CarbonImmutable $captured_at
 * @property CarbonImmutable|null $expires_at
 * @property list<MetricValue>|null $public_metrics
 * @property Provenance $provenance
 * @property array<int, string>|null $human_overrides
 */
class Story extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<StoryFactory> */
    use HasFactory;

    protected $fillable = [
        'platform_account_id',
        'platform',
        'external_id',
        'media_url',
        'media_pruned_at',
        'captured_at',
        'expires_at',
        'public_metrics',
        'provenance',
        'human_overrides',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'media_pruned_at' => 'immutable_datetime',
            'captured_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
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

    /** @return HasMany<EnrichmentRun, $this> */
    public function enrichmentRuns(): HasMany
    {
        return $this->hasMany(EnrichmentRun::class);
    }
}
