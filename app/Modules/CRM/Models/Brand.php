<?php

namespace App\Modules\CRM\Models;

use App\Shared\Enums\SectorLabel;
use App\Shared\Tenancy\BelongsToTenant;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ENT-Brand — a brand belonging to a client; the entity mentions, campaigns,
 * seeding, and products attach to (docs/30-data-model/00-data-model.md#ent-brand).
 *
 * Write-owner: Module 3 CRM (ownership matrix). Module 1 and Module 2 read only.
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $client_id
 * @property string $name
 * @property SectorLabel|null $sector
 * @property array<int, string>|null $aliases
 * @property array<int, string>|null $social_handles
 */
class Brand extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'name',
        'sector',
        'aliases',
        'social_handles',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sector' => SectorLabel::class,
            'aliases' => 'array',
            'social_handles' => 'array',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<Campaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<SeedingCampaign, $this> */
    public function seedingCampaigns(): HasMany
    {
        return $this->hasMany(SeedingCampaign::class);
    }
}
