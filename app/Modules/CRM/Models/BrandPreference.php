<?php

namespace App\Modules\CRM\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Database\Factories\BrandPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ENT-BrandPreference — a creator's brand preferences and restrictions
 * (docs/30-data-model/00-data-model.md#ent-brandpreference, REQ-M3-003).
 *
 * Write-owner: Module 3 CRM (ownership matrix); no reader modules.
 * Preferred/restricted brands are LISTS OF STRING per the canonical shape
 * (names/sectors, not brand FKs). Restrictions act as hard filters when a
 * creator joins a campaign or seeding run (module-3 §2.3, Step-3 logic).
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $creator_id
 * @property array<int, string>|null $preferred_brands
 * @property array<int, string>|null $restricted_brands
 * @property string|null $notes
 */
class BrandPreference extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BrandPreferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'preferred_brands',
        'restricted_brands',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'preferred_brands' => 'array',
            'restricted_brands' => 'array',
        ];
    }

    /** @return BelongsTo<Creator, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }
}
