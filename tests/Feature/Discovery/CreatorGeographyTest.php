<?php

namespace Tests\Feature\Discovery;

use App\Modules\CRM\Models\Creator;
use App\Modules\Discovery\Contracts\CreatorGeography;
use App\Modules\Discovery\Models\GeoAttribution;
use App\Modules\Discovery\Services\CreatorGeographyWriter;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\VerificationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CreatorGeography seam (ADR-0018): the single write path for the M2-owned
 * ENT-GeoAttribution. Operator entries are the HUMAN half of REQ-M2-003 —
 * a full ConfidenceAssessment at HUMAN_REVIEWED with the operator-entry
 * signal; one current row per creator, updated in place, audit-logged.
 */
class CreatorGeographyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_contract_is_bound_to_the_discovery_writer(): void
    {
        $this->assertInstanceOf(CreatorGeographyWriter::class, app(CreatorGeography::class));
    }

    public function test_assign_writes_one_enveloped_row_per_creator_and_updates_in_place(): void
    {
        $creator = Creator::factory()->create();

        $first = app(CreatorGeography::class)->assign($creator, 'de', 'Bavaria', 'Munich');

        $this->assertSame('DE', $first->country_code);
        $this->assertSame('Munich', $first->city);
        // Location is asserted, never a fact (DP-003): HUMAN_REVIEWED at
        // HIGH with the manual-entry signal class (ADR-0015 precedent).
        $this->assertSame('DE', $first->assessment->value);
        $this->assertSame(ConfidenceLevel::High, $first->assessment->confidenceLevel);
        $this->assertSame(['operator-entry'], $first->assessment->signals);
        $this->assertSame(VerificationStatus::HumanReviewed, $first->assessment->verificationStatus);
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator_geography.assigned', 'subject_id' => $first->id]);

        // Re-assign updates the SAME row (one current geography per creator).
        $second = app(CreatorGeography::class)->assign($creator, 'FR', null, 'Paris');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, GeoAttribution::query()->where('creator_id', $creator->id)->count());
        $this->assertSame('FR', $second->refresh()->country_code);
        $this->assertNull($second->region);
    }

    public function test_clear_withdraws_the_assignment_and_audits(): void
    {
        $creator = Creator::factory()->create();
        $row = app(CreatorGeography::class)->assign($creator, 'DE', null, null);

        app(CreatorGeography::class)->clear($creator);

        $this->assertDatabaseMissing('geo_attributions', ['creator_id' => $creator->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator_geography.cleared', 'subject_id' => $row->id]);

        // Clearing an unassigned creator is a quiet no-op.
        app(CreatorGeography::class)->clear($creator);
    }

    public function test_deleting_the_creator_takes_the_assignment_with_it(): void
    {
        $creator = Creator::factory()->create();
        app(CreatorGeography::class)->assign($creator, 'DE', null, null);

        // Lifecycle-coupled (ADR-0018): the geography row never blocks
        // creator deletion — it cascades.
        $creator->delete();

        $this->assertDatabaseMissing('geo_attributions', ['creator_id' => $creator->id]);
    }
}
