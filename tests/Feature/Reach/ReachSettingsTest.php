<?php

namespace Tests\Feature\Reach;

use App\Modules\Monitoring\Livewire\Reach\ReachSettings;
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Platform\Enrichment\Support\ReachConfigurationStatus;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reach settings page (REQ-M1-006): the simplified single-setting editor.
 * Percentages map to weights, saving authors a NEW version and activates
 * it atomically, and the versioned history stays intact underneath.
 */
class ReachSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_admin_save_creates_and_activates_a_configuration(): void
    {
        $admin = $this->makeUser(RoleName::Admin);

        Livewire::actingAs($admin)->test(ReachSettings::class)
            ->set('name', 'Agency reach')
            ->set('all.views', '80')
            ->set('all.followers', '5')
            ->call('save')
            ->assertSet('formError', null)
            ->assertSet('live', true);

        $configuration = ReachConfiguration::query()->sole();
        $this->assertSame(ReachConfigurationStatus::Active, $configuration->status);
        $this->assertSame('Agency reach', $configuration->name);
        $this->assertSame('qds-estimated-reach', $configuration->method);
        $this->assertEqualsWithDelta(0.8, $configuration->params['view_weight'], 1e-9);
        $this->assertEqualsWithDelta(0.05, $configuration->params['follower_weight'], 1e-9);
        $this->assertArrayNotHasKey('platforms', $configuration->params);
    }

    public function test_per_platform_save_writes_explicit_overrides_for_every_platform(): void
    {
        $admin = $this->makeUser(RoleName::Admin);

        Livewire::actingAs($admin)->test(ReachSettings::class)
            ->set('perPlatform', true)
            ->set('platforms.INSTAGRAM.views', '80')
            ->set('platforms.INSTAGRAM.followers', '5')
            ->set('platforms.TIKTOK.views', '60')
            ->set('platforms.TIKTOK.followers', '15')
            ->set('platforms.YOUTUBE.views', '90')
            ->set('platforms.YOUTUBE.followers', '2')
            ->call('save')
            ->assertSet('formError', null);

        $params = ReachConfiguration::query()->sole()->params;
        $this->assertEqualsWithDelta(0.6, $params['platforms']['TIKTOK']['view_weight'], 1e-9);
        $this->assertEqualsWithDelta(0.15, $params['platforms']['TIKTOK']['follower_weight'], 1e-9);
        $this->assertEqualsWithDelta(0.02, $params['platforms']['YOUTUBE']['follower_weight'], 1e-9);
        // Default weights mirror the first platform so future platforms stay valid.
        $this->assertEqualsWithDelta(0.8, $params['view_weight'], 1e-9);
        $this->assertEqualsWithDelta(0.05, $params['follower_weight'], 1e-9);
    }

    public function test_saving_again_supersedes_the_previous_version_and_keeps_history(): void
    {
        $admin = $this->makeUser(RoleName::Admin);

        Livewire::actingAs($admin)->test(ReachSettings::class)->call('save')->assertSet('formError', null);
        Livewire::actingAs($admin)->test(ReachSettings::class)
            ->set('all.views', '50')
            ->call('save')
            ->assertSet('formError', null);

        $this->assertSame(2, ReachConfiguration::query()->count());
        $this->assertSame(1, ReachConfiguration::query()->where('status', ReachConfigurationStatus::Active)->count());
        $this->assertSame(1, ReachConfiguration::query()->where('status', ReachConfigurationStatus::Inactive)->count());
        $active = ReachConfiguration::query()->where('status', ReachConfigurationStatus::Active)->sole();
        $this->assertEqualsWithDelta(0.5, $active->params['view_weight'], 1e-9);
    }

    public function test_zero_follower_percentage_gets_a_friendly_error_and_saves_nothing(): void
    {
        $admin = $this->makeUser(RoleName::Admin);

        Livewire::actingAs($admin)->test(ReachSettings::class)
            ->set('all.followers', '0')
            ->call('save')
            ->assertSet('formError', fn ($v) => is_string($v) && str_contains($v, 'followers'));

        $this->assertSame(0, ReachConfiguration::query()->count());
    }

    public function test_follower_percentage_too_small_to_survive_rounding_gets_a_friendly_error(): void
    {
        // 0.00005% would round to a 0.0 weight and trip the backend's
        // technical GL-PublicViews error; the friendly floor catches it first.
        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(ReachSettings::class)
            ->set('all.followers', '0.00005')
            ->call('save')
            ->assertSet('formError', fn ($v) => is_string($v) && str_contains($v, 'at least 0.0001'));

        $this->assertSame(0, ReachConfiguration::query()->count());
    }

    public function test_comma_decimal_percentages_are_accepted(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(ReachSettings::class)
            ->set('all.views', '72,5')
            ->set('all.followers', '7,5')
            ->call('save')
            ->assertSet('formError', null);

        $params = ReachConfiguration::query()->sole()->params;
        $this->assertEqualsWithDelta(0.725, $params['view_weight'], 1e-9);
        $this->assertEqualsWithDelta(0.075, $params['follower_weight'], 1e-9);
    }

    public function test_overlong_name_gets_a_friendly_error_instead_of_a_500(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(ReachSettings::class)
            ->set('name', str_repeat('a', 256))
            ->call('save')
            ->assertSet('formError', 'Keep the name under 255 characters.');

        $this->assertSame(0, ReachConfiguration::query()->count());
    }

    public function test_fine_grained_weights_round_trip_hydration_without_drifting(): void
    {
        ReachConfiguration::factory()->active()->create([
            'params' => ['view_weight' => 0.7025, 'follower_weight' => 0.000250],
        ]);

        $component = Livewire::actingAs($this->makeUser(RoleName::Admin))->test(ReachSettings::class)
            ->assertSet('all.views', '70.25')
            ->assertSet('all.followers', '0.025');

        $component->call('save')->assertSet('formError', null);

        $active = ReachConfiguration::query()
            ->where('status', ReachConfigurationStatus::Active)
            ->sole();
        $this->assertEqualsWithDelta(0.7025, $active->params['view_weight'], 1e-9);
        $this->assertEqualsWithDelta(0.00025, $active->params['follower_weight'], 1e-9);
    }

    public function test_toggling_per_platform_off_and_on_keeps_hydrated_overrides(): void
    {
        ReachConfiguration::factory()->active()->create([
            'params' => [
                'view_weight' => 0.7,
                'follower_weight' => 0.1,
                'platforms' => ['TIKTOK' => ['view_weight' => 0.5, 'follower_weight' => 0.2]],
            ],
        ]);

        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(ReachSettings::class)
            ->set('perPlatform', false)
            ->set('perPlatform', true)
            ->assertSet('platforms.TIKTOK.views', '50')
            ->assertSet('platforms.TIKTOK.followers', '20');
    }

    public function test_first_switch_to_per_platform_seeds_rows_from_the_shared_values(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(ReachSettings::class)
            ->set('all.views', '55')
            ->set('all.followers', '15')
            ->set('perPlatform', true)
            ->assertSet('platforms.INSTAGRAM.views', '55')
            ->assertSet('platforms.YOUTUBE.followers', '15');
    }

    public function test_saving_records_audit_events(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(ReachSettings::class)
            ->call('save')
            ->assertSet('formError', null);

        $this->assertDatabaseHas('audit_logs', ['action' => 'reach.configuration.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reach.configuration.activated']);
    }

    public function test_hydrates_from_the_active_configuration(): void
    {
        ReachConfiguration::factory()->active()->create([
            'name' => 'Current reach',
            'params' => [
                'view_weight' => 0.75,
                'follower_weight' => 0.125,
                'platforms' => ['TIKTOK' => ['view_weight' => 0.5, 'follower_weight' => 0.2]],
            ],
        ]);

        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(ReachSettings::class)
            ->assertSet('live', true)
            ->assertSet('name', 'Current reach')
            ->assertSet('perPlatform', true)
            ->assertSet('all.views', '75')
            ->assertSet('all.followers', '12.5')
            ->assertSet('platforms.TIKTOK.views', '50')
            ->assertSet('platforms.TIKTOK.followers', '20')
            // Platforms without an override inherit the default weights.
            ->assertSet('platforms.INSTAGRAM.views', '75')
            ->assertSet('platforms.INSTAGRAM.followers', '12.5');
    }

    public function test_analyst_sees_the_page_read_only_and_cannot_save(): void
    {
        $analyst = $this->makeUser(RoleName::Analyst);

        Livewire::actingAs($analyst)->test(ReachSettings::class)
            ->assertSee('Only administrators can change these settings')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, ReachConfiguration::query()->count());
    }
}
