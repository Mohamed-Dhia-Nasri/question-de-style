<?php

namespace App\Modules\Discovery\Models;

use App\Modules\CRM\Models\Creator;
use App\Shared\Casts\AsValueObject;
use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Database\Factories\GeoAttributionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ENT-GeoAttribution — geographic attribution of a Creator (REQ-M2-003).
 * Write-owner: Module 2 Discovery; the ONLY writer is the CreatorGeography
 * seam (ADR-0018 — operator-assigned geography ahead of M2's automatic
 * inference). Inferred-or-asserted location is never a fact: every row
 * embeds a ConfidenceAssessment (DP-003).
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $creator_id
 * @property string|null $country_code
 * @property string|null $region
 * @property string|null $city
 * @property ConfidenceAssessment $assessment
 */
class GeoAttribution extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<GeoAttributionFactory> */
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'country_code',
        'region',
        'city',
        'assessment',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'assessment' => AsValueObject::class.':'.ConfidenceAssessment::class,
        ];
    }

    /** @return BelongsTo<Creator, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }
}
