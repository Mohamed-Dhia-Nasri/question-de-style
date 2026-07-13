<?php

namespace App\Platform\Enrichment\Models;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Support\EnrichmentRunStatus;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One enrichment pass over a ContentItem or Story (operational telemetry —
 * not an ENT-*). `stages` records the outcome of each pipeline stage
 * (hashtags / recognition / sentiment / attribution) with sanitized values
 * only; failures never carry raw provider payloads.
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $content_item_id
 * @property int|null $story_id
 * @property string $correlation_id
 * @property EnrichmentRunStatus $status
 * @property array<string, string>|null $stages
 * @property string|null $error
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
class EnrichmentRun extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'content_item_id',
        'story_id',
        'correlation_id',
        'status',
        'stages',
        'error',
        'started_at',
        'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => EnrichmentRunStatus::class,
            'stages' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
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
}
