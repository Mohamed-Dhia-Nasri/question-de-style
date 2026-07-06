<?php

namespace App\Modules\CRM\Models;

use App\Modules\Monitoring\Models\ContentItem;
use App\Shared\Casts\AsValueObject;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\ValueObjects\MetricValue;
use Carbon\CarbonImmutable;
use Database\Factories\ShipmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * ENT-Shipment — a physical shipment within a seeding campaign
 * (docs/30-data-model/00-data-model.md#ent-shipment, REQ-M3-007).
 *
 * Write-owner: Module 3 CRM (ownership matrix); no reader modules.
 * `product_id` is required — the cross-creator aggregation key
 * (REQ-M3-013). `product_value_at_ship` carries tier CONFIRMED
 * (agency-known value of goods). resultingContentIds is the
 * shipment_resulting_content pivot (spec D2), empty until REQ-M3-008
 * matching lands in Step 3.
 *
 * @property int $id
 * @property int $seeding_campaign_id
 * @property int $creator_id
 * @property ShipmentStatus $status
 * @property string|null $tracking_number
 * @property CarbonImmutable|null $shipped_at
 * @property CarbonImmutable|null $delivered_at
 * @property int $product_id
 * @property int|null $quantity
 * @property MetricValue|null $product_value_at_ship
 * @property bool|null $posting_required
 * @property bool|null $posted
 * @property CarbonImmutable|null $posted_at
 */
class Shipment extends Model
{
    /** @use HasFactory<ShipmentFactory> */
    use HasFactory;

    protected $fillable = [
        'seeding_campaign_id',
        'creator_id',
        'status',
        'tracking_number',
        'shipped_at',
        'delivered_at',
        'product_id',
        'quantity',
        'product_value_at_ship',
        'posting_required',
        'posted',
        'posted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'shipped_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'quantity' => 'integer',
            'product_value_at_ship' => AsValueObject::class.':'.MetricValue::class,
            'posting_required' => 'boolean',
            'posted' => 'boolean',
            'posted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SeedingCampaign, $this> */
    public function seedingCampaign(): BelongsTo
    {
        return $this->belongsTo(SeedingCampaign::class);
    }

    /** @return BelongsTo<Creator, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Content matched to this shipment via REQ-M3-008 — the join to
     * metrics. Rows are written by Step-3 matching only.
     *
     * @return BelongsToMany<ContentItem, $this>
     */
    public function resultingContent(): BelongsToMany
    {
        return $this->belongsToMany(ContentItem::class, 'shipment_resulting_content')->withTimestamps();
    }
}
