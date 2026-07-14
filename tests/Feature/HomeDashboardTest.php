<?php

namespace Tests\Feature;

use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SeedingCampaignStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Home dashboard (/dashboard): live tiles replacing the static P0
 * placeholder — roster count, 30d mentions, active campaigns/seeding runs,
 * rollup-backed estimated reach (tier-labelled, unavailable when absent —
 * DEF-003 is never fabricated), and the latest-content activity feed.
 * CLIENT_VIEWER stays confined away from it (ADR-0016).
 */
class HomeDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_dashboard_renders_live_counts_and_activity(): void
    {
        $creator = Creator::factory()->create(['display_name' => 'Style Ikone']);
        $account = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
        ]);

        MonitoredSubject::factory()->create([
            'subject_type' => MonitoredSubjectType::Creator->value,
            'creator_id' => $creator->id,
            'active' => true,
        ]);

        Mention::factory()->count(3)->create();

        Campaign::factory()->count(2)->create(['status' => CampaignStatus::Active]);
        Campaign::factory()->create(['status' => CampaignStatus::Draft]);
        SeedingCampaign::factory()->create(['status' => SeedingCampaignStatus::Shipping]);

        ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'caption' => 'Herbstlooks mit neuer Tasche',
            'published_at' => now()->subHours(2),
        ]);

        $this->actingAs($this->makeUser(RoleName::Analyst));

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Tracked creators')
            ->assertSee('Mentions (30d)')
            ->assertSee('Active campaigns')
            ->assertSee('seeding run in flight')
            ->assertSee('Latest content')
            ->assertSee('Herbstlooks mit neuer Tasche')
            ->assertSee('Style Ikone')
            // No rollup rows exist — estimated reach must be an honest
            // "not yet computed" state, never a fabricated 0, and never
            // DEF-003 (that's CONFIRMED-reach only, retired from this
            // ESTIMATED surface now that ADR-0022 documents the method).
            ->assertSee('No estimated-reach figures in the rollups for this period yet', false)
            ->assertSee('REQ-M1-006', false)
            ->assertDontSee('DEF-003')
            // The static P0 placeholder copy must be gone.
            ->assertDontSee('Available when Monitoring ships');
    }

    public function test_dashboard_with_empty_database_shows_zeroes_and_empty_state(): void
    {
        $this->actingAs($this->makeUser(RoleName::Analyst));

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Tracked creators')
            ->assertSee('No activity yet');
    }

    public function test_client_viewer_never_reaches_the_dashboard(): void
    {
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $this->get('/dashboard')->assertForbidden();
    }
}
