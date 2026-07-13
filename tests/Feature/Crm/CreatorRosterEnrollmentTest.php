<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Contracts\CreatorProposals;
use App\Modules\CRM\DTO\CreatorProposal;
use App\Modules\CRM\Services\CreatorWriter;
use App\Modules\Monitoring\Contracts\RosterEnrollment;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Services\RosterEnrollmentService;
use App\Platform\Ingestion\Jobs\PollMonitoredAccountJob;
use App\Platform\Ingestion\Jobs\RunMonitoringCycleJob;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\AdaptiveCadence;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Roster enrollment seam (M3 → M1): every creator is on the active
 * monitoring roster from the moment it exists — creation through
 * CreatorWriter (and therefore the CRM UI and the XMC-001 intake) enrolls
 * an active CREATOR MonitoredSubject, so the next scheduled cycle
 * (AC-M1-001) polls the creator without any operator action.
 */
class CreatorRosterEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_creator_enrolls_it_on_the_active_roster(): void
    {
        $creator = app(CreatorWriter::class)->createCreator('Neue Kreatorin');

        $subject = MonitoredSubject::query()
            ->where('creator_id', $creator->id)
            ->sole();

        $this->assertSame(MonitoredSubjectType::Creator, $subject->subject_type);
        $this->assertTrue($subject->active);
        $this->assertSame('Neue Kreatorin', $subject->label);
        // Empty platform list = no filter: every account, present and future.
        $this->assertCount(0, $subject->platforms ?? collect());
    }

    public function test_enrollment_is_idempotent_and_preserves_operator_configuration(): void
    {
        $creator = app(CreatorWriter::class)->createCreator('Konfigurierte Kreatorin');

        $subject = MonitoredSubject::query()->where('creator_id', $creator->id)->sole();
        $subject->update(['platforms' => [Platform::TikTok], 'active' => false]);

        app(RosterEnrollment::class)->enroll($creator);

        $subject = MonitoredSubject::query()->where('creator_id', $creator->id)->sole();
        $this->assertFalse($subject->active);
        $this->assertTrue($subject->platforms?->contains(Platform::TikTok) ?? false);
    }

    public function test_an_xmc001_proposal_lands_on_the_roster_too(): void
    {
        $creator = app(CreatorProposals::class)->propose(new CreatorProposal(
            'Vorgeschlagene Kreatorin',
            Platform::Instagram,
            'proposed.creator',
            null,
            [],
            null,
            new Provenance(SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER, CarbonImmutable::now(), 'test-fixture-v1'),
        ));

        $this->assertDatabaseHas('monitored_subjects', [
            'creator_id' => $creator->id,
            'subject_type' => MonitoredSubjectType::Creator->value,
            'active' => true,
        ]);
    }

    public function test_the_next_scheduled_cycle_polls_a_freshly_created_creator(): void
    {
        $writer = app(CreatorWriter::class);
        $creator = $writer->createCreator('Sofort Beobachtet');
        $account = $writer->addManualPlatformAccount($creator, Platform::Instagram, 'sofort.ig');

        Queue::fake();

        (new RunMonitoringCycleJob)->handle(app(AdaptiveCadence::class));

        Queue::assertPushed(
            fn (PollMonitoredAccountJob $job) => $job->platformAccountId === $account->id,
        );
    }

    public function test_the_seam_is_bound_to_the_monitoring_side_service(): void
    {
        $this->assertInstanceOf(RosterEnrollmentService::class, app(RosterEnrollment::class));
    }
}
