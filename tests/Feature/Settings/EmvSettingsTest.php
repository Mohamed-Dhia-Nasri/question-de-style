<?php

namespace Tests\Feature\Settings;

use App\Modules\Monitoring\Livewire\Emv\EmvSettings;
use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Platform\Enrichment\Support\EmvConfigurationStatus;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EMV settings page (REQ-M1-011): the simplified single-setting editor.
 * Chips + rate fields map to the canonical Σ (metric × rate) rate card,
 * saving authors a NEW version and activates it atomically, and the
 * versioned history stays intact underneath.
 */
class EmvSettingsTest extends TestCase
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

        Livewire::actingAs($admin)->test(EmvSettings::class)
            ->set('name', 'Agency EMV')
            ->set('currency', 'USD')
            ->call('toggleMetric', 'comments') // views + likes remain
            ->set('rates.views', '0.01')
            ->set('rates.likes', '0.05')
            ->call('save')
            ->assertSet('formError', null)
            ->assertSet('live', true);

        $configuration = EmvConfiguration::query()->sole();
        $this->assertSame(EmvConfigurationStatus::Active, $configuration->status);
        $this->assertSame('Agency EMV', $configuration->name);
        $this->assertSame('USD', $configuration->currency);
        $this->assertSame(['views', 'likes'], $configuration->formula['metrics']);
        $this->assertEqualsWithDelta(0.01, $configuration->rates['default']['views'], 1e-9);
        $this->assertEqualsWithDelta(0.05, $configuration->rates['default']['likes'], 1e-9);
        $this->assertArrayNotHasKey('platforms', $configuration->rates);
        $this->assertArrayNotHasKey('content_types', $configuration->rates);
    }

    public function test_platform_and_format_overrides_only_include_filled_cells(): void
    {
        $admin = $this->makeUser(RoleName::Admin);

        Livewire::actingAs($admin)->test(EmvSettings::class)
            ->set('rates.views', '0.01')
            ->set('rates.likes', '0.05')
            ->set('rates.comments', '0.2')
            ->set('byPlatform', true)
            ->set('platformRates.INSTAGRAM.views', '0.02')
            ->set('byFormat', true)
            ->set('formatRates.REEL.likes', '0.08')
            ->call('save')
            ->assertSet('formError', null);

        $rates = EmvConfiguration::query()->sole()->rates;
        $this->assertSame(['views'], array_keys($rates['platforms']['INSTAGRAM']));
        $this->assertEqualsWithDelta(0.02, $rates['platforms']['INSTAGRAM']['views'], 1e-9);
        $this->assertArrayNotHasKey('TIKTOK', $rates['platforms']);
        $this->assertSame(['likes'], array_keys($rates['content_types']['REEL']));
        $this->assertEqualsWithDelta(0.08, $rates['content_types']['REEL']['likes'], 1e-9);
        $this->assertArrayNotHasKey('VIDEO', $rates['content_types']);
    }

    public function test_disabled_toggles_omit_override_sections_even_when_grids_have_values(): void
    {
        $admin = $this->makeUser(RoleName::Admin);

        Livewire::actingAs($admin)->test(EmvSettings::class)
            ->set('rates.views', '0.01')
            ->set('rates.likes', '0.05')
            ->set('rates.comments', '0.2')
            ->set('platformRates.INSTAGRAM.views', '0.02')
            ->call('save')
            ->assertSet('formError', null);

        $this->assertArrayNotHasKey('platforms', EmvConfiguration::query()->sole()->rates);
    }

    public function test_saving_again_supersedes_the_previous_version_and_keeps_history(): void
    {
        $admin = $this->makeUser(RoleName::Admin);
        $rates = fn () => Livewire::actingAs($admin)->test(EmvSettings::class)
            ->set('rates.views', '0.01')
            ->set('rates.likes', '0.05')
            ->set('rates.comments', '0.2');

        $rates()->call('save')->assertSet('formError', null);
        $rates()->set('rates.views', '0.03')->call('save')->assertSet('formError', null);

        $this->assertSame(2, EmvConfiguration::query()->count());
        $this->assertSame(1, EmvConfiguration::query()->where('status', EmvConfigurationStatus::Active)->count());
        $active = EmvConfiguration::query()->where('status', EmvConfigurationStatus::Active)->sole();
        $this->assertEqualsWithDelta(0.03, $active->rates['default']['views'], 1e-9);
    }

    public function test_no_selected_interactions_gets_a_friendly_error(): void
    {
        $admin = $this->makeUser(RoleName::Admin);

        Livewire::actingAs($admin)->test(EmvSettings::class)
            ->call('toggleMetric', 'views')
            ->call('toggleMetric', 'likes')
            ->call('toggleMetric', 'comments')
            ->call('save')
            ->assertSet('formError', 'Choose at least one interaction to count — EMV needs something to add up.');

        $this->assertSame(0, EmvConfiguration::query()->count());
    }

    public function test_missing_rate_for_a_selected_interaction_gets_a_friendly_error(): void
    {
        $admin = $this->makeUser(RoleName::Admin);

        Livewire::actingAs($admin)->test(EmvSettings::class)
            ->set('rates.views', '0.01')
            ->set('rates.likes', '0.05')
            ->call('save')
            ->assertSet('formError', fn ($v) => is_string($v) && str_contains($v, 'One comment'));

        $this->assertSame(0, EmvConfiguration::query()->count());
    }

    public function test_hydrates_from_the_active_configuration(): void
    {
        EmvConfiguration::factory()->active()->create([
            'name' => 'Current EMV',
            'currency' => 'EUR',
            'formula' => ['model' => 'rate_card_sum', 'metrics' => ['views', 'saves']],
            'rates' => [
                'default' => ['views' => 0.012, 'saves' => 0.1],
                'platforms' => ['TIKTOK' => ['views' => 0.02]],
            ],
        ]);

        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(EmvSettings::class)
            ->assertSet('live', true)
            ->assertSet('name', 'Current EMV')
            ->assertSet('enabled.views', true)
            ->assertSet('enabled.saves', true)
            ->assertSet('enabled.likes', false)
            ->assertSet('rates.views', '0.012')
            ->assertSet('rates.saves', '0.1')
            ->assertSet('byPlatform', true)
            ->assertSet('byFormat', false)
            ->assertSet('platformRates.TIKTOK.views', '0.02');
    }

    public function test_comma_decimal_rates_are_accepted_in_base_fields_and_grids(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(EmvSettings::class)
            ->set('rates.views', '0,01')
            ->set('rates.likes', '0,05')
            ->set('rates.comments', '0,2')
            ->set('byPlatform', true)
            ->set('platformRates.INSTAGRAM.views', '0,02')
            ->call('save')
            ->assertSet('formError', null);

        $rates = EmvConfiguration::query()->sole()->rates;
        $this->assertEqualsWithDelta(0.01, $rates['default']['views'], 1e-9);
        $this->assertEqualsWithDelta(0.05, $rates['default']['likes'], 1e-9);
        $this->assertEqualsWithDelta(0.02, $rates['platforms']['INSTAGRAM']['views'], 1e-9);
    }

    public function test_fine_grained_rates_round_trip_hydration_without_drifting(): void
    {
        EmvConfiguration::factory()->active()->create([
            'formula' => ['model' => 'rate_card_sum', 'metrics' => ['views']],
            'rates' => ['default' => ['views' => 0.00025]],
        ]);

        $component = Livewire::actingAs($this->makeUser(RoleName::Admin))->test(EmvSettings::class)
            ->assertSet('rates.views', '0.00025');

        $component->call('save')->assertSet('formError', null);

        $active = EmvConfiguration::query()
            ->where('status', EmvConfigurationStatus::Active)
            ->sole();
        $this->assertEqualsWithDelta(0.00025, $active->rates['default']['views'], 1e-9);
    }

    public function test_absurdly_large_rate_gets_a_friendly_error_instead_of_a_500(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(EmvSettings::class)
            ->set('rates.views', '1e309') // float INF — would crash JSON encoding
            ->set('rates.likes', '0.05')
            ->set('rates.comments', '0.2')
            ->call('save')
            ->assertSet('formError', fn ($v) => is_string($v) && str_contains($v, '1,000,000'));

        $this->assertSame(0, EmvConfiguration::query()->count());
    }

    public function test_overlong_name_gets_a_friendly_error_instead_of_a_500(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(EmvSettings::class)
            ->set('name', str_repeat('a', 256))
            ->set('rates.views', '0.01')
            ->set('rates.likes', '0.05')
            ->set('rates.comments', '0.2')
            ->call('save')
            ->assertSet('formError', 'Keep the name under 255 characters.');

        $this->assertSame(0, EmvConfiguration::query()->count());
    }

    public function test_a_legacy_currency_outside_the_select_list_is_preserved(): void
    {
        EmvConfiguration::factory()->active()->create(['currency' => 'CHF']);

        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(EmvSettings::class)
            ->assertSet('currency', 'CHF')
            ->set('rates.views', '0.01')
            ->set('rates.likes', '0.05')
            ->set('rates.comments', '0.2')
            ->call('save')
            ->assertSet('formError', null);

        $active = EmvConfiguration::query()
            ->where('status', EmvConfigurationStatus::Active)
            ->sole();
        $this->assertSame('CHF', $active->currency);
    }

    public function test_saving_records_audit_events(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(EmvSettings::class)
            ->set('rates.views', '0.01')
            ->set('rates.likes', '0.05')
            ->set('rates.comments', '0.2')
            ->call('save')
            ->assertSet('formError', null);

        $this->assertDatabaseHas('audit_logs', ['action' => 'emv.configuration.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'emv.configuration.activated']);
    }

    public function test_analyst_sees_the_page_read_only_and_cannot_save(): void
    {
        $analyst = $this->makeUser(RoleName::Analyst);

        Livewire::actingAs($analyst)->test(EmvSettings::class)
            ->assertSee('Only administrators can change these settings')
            ->set('rates.views', '0.01')
            ->set('rates.likes', '0.05')
            ->set('rates.comments', '0.2')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, EmvConfiguration::query()->count());
    }
}
