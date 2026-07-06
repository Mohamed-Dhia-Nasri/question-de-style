<?php

namespace App\Modules\CRM\Models;

use App\Shared\Casts\AsValueObject;
use App\Shared\Enums\SectorLabel;
use App\Shared\ValueObjects\MetricValue;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ENT-Product — a product/SKU under a brand; the key that aggregates
 * seeding results across creators (docs/30-data-model/00-data-model.md#ent-product,
 * REQ-M3-013).
 *
 * Write-owner: Module 3 CRM (ownership matrix); M1/M2 read only.
 * Manual/internal entity — no Provenance envelope. `unit_value` carries
 * tier CONFIRMED (agency-known price).
 *
 * @property int $id
 * @property int $brand_id
 * @property string $name
 * @property string|null $sku
 * @property string|null $variant
 * @property MetricValue|null $unit_value
 * @property SectorLabel|null $category
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'name',
        'sku',
        'variant',
        'unit_value',
        'category',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_value' => AsValueObject::class.':'.MetricValue::class,
            'category' => SectorLabel::class,
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<SeedingCampaign, $this> */
    public function seedingCampaigns(): HasMany
    {
        return $this->hasMany(SeedingCampaign::class);
    }

    /** @return HasMany<Shipment, $this> */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
