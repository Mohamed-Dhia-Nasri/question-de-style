<?php

namespace App\Modules\Discovery\Services;

use App\Modules\CRM\Models\Creator;
use App\Modules\Discovery\Contracts\CreatorGeography;
use App\Modules\Discovery\Models\GeoAttribution;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\VerificationStatus;
use App\Shared\Tenancy\TenantContext;
use App\Shared\ValueObjects\ConfidenceAssessment;
use RuntimeException;

/**
 * M2-side implementation of the CreatorGeography seam (ADR-0018): the single
 * write path for ENT-GeoAttribution. One current row per creator (v1),
 * updated in place — the audit trail carries every change.
 *
 * The envelope encodes what this row IS: a human assertion, not an
 * inference — value = the assessed country, signals name the entry surface
 * (operator-entry, the ADR-0015 manual-entry class), HIGH confidence at
 * HUMAN_REVIEWED. Never presented as more than an assessment (DP-003).
 */
class CreatorGeographyWriter implements CreatorGeography
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    public function assign(Creator $creator, ?string $countryCode, ?string $region, ?string $city): GeoAttribution
    {
        $this->assertSameTenant($creator);

        $attribution = GeoAttribution::query()->firstOrNew(['creator_id' => $creator->id]);

        $attribution->fill([
            'country_code' => $countryCode !== null ? strtoupper($countryCode) : null,
            'region' => $region,
            'city' => $city,
            'assessment' => new ConfidenceAssessment(
                $countryCode !== null ? strtoupper($countryCode) : null,
                ConfidenceLevel::High,
                ['operator-entry'],
                VerificationStatus::HumanReviewed,
            ),
        ]);

        $attribution->save();

        $this->audit->record('creator_geography.assigned', $attribution, [
            'creator_id' => $creator->id,
            'country_code' => $attribution->country_code,
            'region' => $attribution->region,
            'city' => $attribution->city,
        ]);

        return $attribution;
    }

    public function clear(Creator $creator): void
    {
        $this->assertSameTenant($creator);

        $attribution = GeoAttribution::query()->where('creator_id', $creator->id)->first();

        if ($attribution === null) {
            return;
        }

        $attribution->delete();

        $this->audit->record('creator_geography.cleared', $attribution, [
            'creator_id' => $creator->id,
        ]);
    }

    /**
     * The seam defends itself instead of inheriting safety from a bound
     * context (ADR-0019): this mutating write must run under exactly the
     * creator's tenant. A context-less or cross-tenant call fails closed —
     * a future P2 inference pipeline that reaches this seam must runAs the
     * creator's tenant first, exactly as the enrichment jobs do.
     */
    private function assertSameTenant(Creator $creator): void
    {
        $tenantId = $this->tenant->idOrFail();

        if ((int) $creator->tenant_id !== $tenantId) {
            throw new RuntimeException(
                'CreatorGeography seam invoked for a creator outside the active tenant context.'
            );
        }
    }
}
