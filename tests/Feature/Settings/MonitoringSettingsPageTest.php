<?php

namespace Tests\Feature\Settings;

use App\Modules\Monitoring\Livewire\Settings\MonitoringSettings;
use App\Modules\Monitoring\Models\MonitoringSetting;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings → Monitoring (ADR-0024/0025): one page, four plain-language
 * values. Saves append a new history row; admins only; friendly errors.
 */
class MonitoringSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_defaults_hydrate_from_config_when_no_row_exists(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(MonitoringSettings::class)
            ->assertSet('shipmentDays', '60')
            ->assertSet('trendDays', '30')
            ->assertSet('storyCleanupEnabled', true)
            ->assertSet('storyDays', '180')
            ->assertSet('commsCleanupEnabled', false);
    }

    public function test_admin_save_appends_a_new_row_with_all_four_values(): void
    {
        $admin = $this->makeUser(RoleName::Admin);

        Livewire::actingAs($admin)->test(MonitoringSettings::class)
            ->set('shipmentDays', '45')
            ->set('trendDays', '14')
            ->set('storyCleanupEnabled', true)
            ->set('storyDays', '90')
            ->set('commsCleanupEnabled', true)
            ->set('commsDays', '365')
            ->call('save')
            ->assertSet('formError', null);

        $row = MonitoringSetting::query()->withoutGlobalScopes()->sole();
        $this->assertSame(45, $row->shipment_window_days);
        $this->assertSame(14, $row->engagement_trend_window_days);
        $this->assertSame(90, $row->story_retention_days);
        $this->assertSame(365, $row->communication_retention_days);
        $this->assertSame($admin->id, $row->updated_by);
        $this->assertSame($admin->tenant_id, $row->tenant_id);
    }

    public function test_disabled_cleanup_toggles_store_zero_meaning_keep_forever(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(MonitoringSettings::class)
            ->set('storyCleanupEnabled', false)
            ->set('commsCleanupEnabled', false)
            ->call('save')
            ->assertSet('formError', null);

        $row = MonitoringSetting::query()->withoutGlobalScopes()->sole();
        $this->assertSame(0, $row->story_retention_days);
        $this->assertSame(0, $row->communication_retention_days);
    }

    public function test_saving_twice_appends_history_rows(): void
    {
        $admin = $this->makeUser(RoleName::Admin);

        Livewire::actingAs($admin)->test(MonitoringSettings::class)->call('save');
        Livewire::actingAs($admin)->test(MonitoringSettings::class)
            ->set('shipmentDays', '30')->call('save');

        $this->assertSame(2, MonitoringSetting::query()->withoutGlobalScopes()->count());
        $this->assertSame(
            30,
            MonitoringSetting::query()->withoutGlobalScopes()->latest('id')->first()->shipment_window_days,
        );
    }

    public function test_non_admin_staff_cannot_save(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::Analyst))->test(MonitoringSettings::class)
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, MonitoringSetting::query()->withoutGlobalScopes()->count());
    }

    public function test_friendly_errors_reject_out_of_range_values(): void
    {
        $page = Livewire::actingAs($this->makeUser(RoleName::Admin))->test(MonitoringSettings::class);

        $page->set('shipmentDays', '0')->call('save');
        $this->assertNotNull($page->get('formError'));

        $page->set('shipmentDays', '60')->set('trendDays', '5')->call('save');
        $this->assertNotNull($page->get('formError'));

        $page->set('trendDays', '30')->set('storyCleanupEnabled', true)->set('storyDays', 'abc')->call('save');
        $this->assertNotNull($page->get('formError'));

        $this->assertSame(0, MonitoringSetting::query()->withoutGlobalScopes()->count());
    }

    public function test_existing_latest_row_hydrates_the_form(): void
    {
        $admin = $this->makeUser(RoleName::Admin);
        $row = new MonitoringSetting([
            'shipment_window_days' => 21,
            'engagement_trend_window_days' => 60,
            'story_retention_days' => 0,
            'communication_retention_days' => 730,
        ]);
        $row->tenant_id = $admin->tenant_id;
        $row->save();

        Livewire::actingAs($admin)->test(MonitoringSettings::class)
            ->assertSet('shipmentDays', '21')
            ->assertSet('trendDays', '60')
            ->assertSet('storyCleanupEnabled', false)
            ->assertSet('commsCleanupEnabled', true)
            ->assertSet('commsDays', '730');
    }
}
