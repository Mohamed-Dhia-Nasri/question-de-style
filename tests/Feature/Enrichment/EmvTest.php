<?php

namespace Tests\Feature\Enrichment;

use App\Models\User;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Modules\Monitoring\Models\EmvResult;
use App\Platform\Enrichment\Emv\EmvCalculator;
use App\Platform\Enrichment\Emv\EmvConfigurationService;
use App\Platform\Enrichment\Emv\EmvConfigurationValidator;
use App\Platform\Enrichment\Support\EmvConfigurationStatus;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\RoleName;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * EMV (REQ-M1-011, MET-EMV, AC-M1-011): versioned operator-authored
 * configurations, ADMIN-only lifecycle (emv.manage), and append-only
 * calculation results that stay reproducible across configuration changes.
 * EMV is a modeled monetary ESTIMATE — unavailable (null) until a valid
 * configuration is active, and NEVER zero when inputs are missing.
 */
class EmvTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    /** @return array<string, mixed> */
    private function configAttributes(string $version = 'v1', array $overrides = []): array
    {
        return [
            'name' => "EMV model {$version}",
            'formula_version' => "formula-{$version}",
            'rate_card_version' => "rates-{$version}",
            'currency' => 'EUR',
            'formula' => [
                'model' => EmvConfigurationValidator::MODEL_RATE_CARD_SUM,
                'metrics' => ['views', 'likes', 'comments'],
            ],
            'rates' => [
                'default' => ['views' => 0.01, 'likes' => 0.05, 'comments' => 0.2],
            ],
            'effective_from' => now()->subDay()->toDateString(),
            ...$overrides,
        ];
    }

    /** Instagram content observing views=1000 and likes=100 — comments never reported. */
    private function contentWithViewsAndLikes(): ContentItem
    {
        return ContentItem::factory()->create([
            'public_metrics' => [
                new MetricValue(1000, MetricTier::Public, 'views'),
                new MetricValue(100, MetricTier::Public, 'likes'),
            ],
        ]);
    }

    private function activeConfiguration(User $admin, string $version = 'v1', array $overrides = []): EmvConfiguration
    {
        $service = app(EmvConfigurationService::class);
        $configuration = $service->create($this->configAttributes($version, $overrides), $admin);

        return $service->activate($configuration, $admin);
    }

    public function test_emv_is_unavailable_until_a_configuration_is_active_and_is_never_zero(): void
    {
        $content = $this->contentWithViewsAndLikes();
        $calculator = app(EmvCalculator::class);

        // No configuration at all → unavailable, not 0.
        $this->assertNull($calculator->calculate($content));

        // A DRAFT configuration is not the active model either.
        EmvConfiguration::factory()->create();

        $this->assertNull($calculator->calculate($content));
        $this->assertDatabaseCount('emv_results', 0);
    }

    public function test_admin_creates_a_draft_configuration_with_an_audit_trail(): void
    {
        $admin = $this->makeUser(RoleName::Admin);

        $configuration = app(EmvConfigurationService::class)->create($this->configAttributes(), $admin);

        $this->assertSame(EmvConfigurationStatus::Draft, $configuration->status);
        $this->assertSame($admin->id, $configuration->created_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'emv.configuration.created',
            'subject_id' => $configuration->id,
        ]);
    }

    public function test_analyst_cannot_create_a_configuration(): void
    {
        // emv.manage is ADMIN-only: an unauthorized EMV change must be refused.
        $analyst = $this->makeUser(RoleName::Analyst);

        $this->expectException(AuthorizationException::class);

        app(EmvConfigurationService::class)->create($this->configAttributes(), $analyst);
    }

    public function test_invalid_formula_is_rejected_at_creation_before_anything_persists(): void
    {
        $admin = $this->makeUser(RoleName::Admin);
        $attributes = $this->configAttributes();
        $attributes['formula']['model'] = 'custom_expression';

        try {
            app(EmvConfigurationService::class)->create($attributes, $admin);
            $this->fail('An invalid formula model must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('formula.model', $exception->getMessage());
        }

        $this->assertDatabaseCount('emv_configurations', 0);
    }

    public function test_activation_stamps_the_actor_and_keeps_a_single_active_configuration(): void
    {
        $admin = $this->makeUser(RoleName::Admin);
        $service = app(EmvConfigurationService::class);

        $first = $service->activate($service->create($this->configAttributes('v1'), $admin), $admin);

        $this->assertSame(EmvConfigurationStatus::Active, $first->status);
        $this->assertSame($admin->id, $first->activated_by);
        $this->assertNotNull($first->activated_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'emv.configuration.activated',
            'subject_id' => $first->id,
        ]);

        // Activating a second version atomically retires the first.
        $second = $service->activate($service->create($this->configAttributes('v2'), $admin), $admin);

        $this->assertSame(EmvConfigurationStatus::Active, $second->status);
        $this->assertSame(EmvConfigurationStatus::Inactive, $first->fresh()->status);
        $this->assertSame(1, EmvConfiguration::query()->where('status', EmvConfigurationStatus::Active)->count());
    }

    public function test_deactivate_and_archive_lifecycle_is_audited_and_archived_cannot_reactivate(): void
    {
        $admin = $this->makeUser(RoleName::Admin);
        $service = app(EmvConfigurationService::class);
        $configuration = $this->activeConfiguration($admin);

        $service->deactivate($configuration, $admin);
        $this->assertSame(EmvConfigurationStatus::Inactive, $configuration->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'emv.configuration.deactivated',
            'subject_id' => $configuration->id,
        ]);

        $service->archive($configuration, $admin);
        $this->assertSame(EmvConfigurationStatus::Archived, $configuration->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'emv.configuration.archived',
            'subject_id' => $configuration->id,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('archived');

        $service->activate($configuration->fresh(), $admin);
    }

    public function test_only_draft_configurations_are_editable(): void
    {
        // Activated versions are immutable so past results stay reproducible
        // (AC-M1-011): changing rates means authoring a NEW version.
        $admin = $this->makeUser(RoleName::Admin);
        $configuration = $this->activeConfiguration($admin);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only DRAFT');

        app(EmvConfigurationService::class)->update($configuration, ['name' => 'Edited'], $admin);
    }

    public function test_calculation_disclosure_covers_value_inputs_rates_and_version_snapshots(): void
    {

        $admin = $this->makeUser(RoleName::Admin);
        $configuration = $this->activeConfiguration($admin);
        $content = $this->contentWithViewsAndLikes();

        $result = app(EmvCalculator::class)->calculate($content)->fresh();

        // Σ (metric_i × rate_i) = 1000×0.01 + 100×0.05 — comments contribute
        // NOTHING because missing is never zero.
        $this->assertSame(15.0, $result->value->amount);
        $this->assertSame(MetricTier::Estimated, $result->value->tier, 'A modeled monetary estimate is never presented as fact (DP-001).');
        $this->assertSame('emv', $result->value->metric);

        $this->assertSame($content->id, $result->content_item_id);
        $this->assertSame($configuration->id, $result->emv_configuration_id);
        $this->assertSame('EUR', $result->currency);
        $this->assertSame('formula-v1', $result->formula_version);
        $this->assertSame('rates-v1', $result->rate_card_version);
        $this->assertNotNull($result->calculated_at);

        $inputs = collect($result->inputs)->keyBy('metric');

        $this->assertTrue($inputs['views']['included']);
        $this->assertEquals(1000.0, $inputs['views']['amount']);
        $this->assertSame(MetricTier::Public->value, $inputs['views']['tier']);
        $this->assertSame(0.01, $inputs['views']['rate']);

        $this->assertTrue($inputs['likes']['included']);
        $this->assertEquals(100.0, $inputs['likes']['amount']);
        $this->assertSame(0.05, $inputs['likes']['rate']);

        // The unobserved metric is disclosed as unavailable — null, not 0.
        $this->assertFalse($inputs['comments']['included']);
        $this->assertNull($inputs['comments']['amount']);
        $this->assertNull($inputs['comments']['tier']);
    }

    public function test_platform_rate_overrides_take_precedence_and_the_source_is_disclosed(): void
    {

        $admin = $this->makeUser(RoleName::Admin);
        $this->activeConfiguration($admin, 'v1', [
            'rates' => [
                'default' => ['views' => 0.01, 'likes' => 0.05, 'comments' => 0.2],
                'platforms' => ['INSTAGRAM' => ['views' => 0.03]],
            ],
        ]);
        $content = $this->contentWithViewsAndLikes();

        $result = app(EmvCalculator::class)->calculate($content)->fresh();

        // views uses the INSTAGRAM override: 1000×0.03 + 100×0.05.
        $this->assertSame(35.0, $result->value->amount);

        $inputs = collect($result->inputs)->keyBy('metric');
        $this->assertSame(0.03, $inputs['views']['rate']);
        $this->assertSame('platforms.INSTAGRAM', $inputs['views']['rate_source']);
        $this->assertSame('default', $inputs['likes']['rate_source']);
    }

    public function test_configuration_changes_never_alter_previously_calculated_results(): void
    {

        $admin = $this->makeUser(RoleName::Admin);
        $service = app(EmvConfigurationService::class);
        $calculator = app(EmvCalculator::class);
        $content = $this->contentWithViewsAndLikes();

        $v1 = $this->activeConfiguration($admin, 'v1');
        $oldResult = $calculator->calculate($content);
        $this->assertSame(15.0, $oldResult->value->amount);

        $service->deactivate($v1, $admin);
        $this->activeConfiguration($admin, 'v2', [
            'rates' => ['default' => ['views' => 0.02, 'likes' => 0.1, 'comments' => 0.4]],
        ]);

        // The historical row is untouched in the DB and still names the
        // rate card that produced it (AC-M1-011 reproducibility).
        $unchanged = $oldResult->fresh();
        $this->assertSame(15.0, $unchanged->value->amount);
        $this->assertSame('formula-v1', $unchanged->formula_version);
        $this->assertSame('rates-v1', $unchanged->rate_card_version);

        $newResult = $calculator->calculate($content)->fresh();
        $this->assertSame(1000 * 0.02 + 100 * 0.1, $newResult->value->amount);
        $this->assertSame('formula-v2', $newResult->formula_version);
        $this->assertSame('rates-v2', $newResult->rate_card_version);
    }

    public function test_results_are_append_only(): void
    {
        // The row is inserted directly (not via EmvCalculator, which is
        // currently unable to persist — see the APP BUG skips above) so the
        // model-level append-only guarantee is still verified.
        $configuration = EmvConfiguration::factory()->active()->create();
        $content = $this->contentWithViewsAndLikes();

        $result = EmvResult::query()->create([
            'content_item_id' => $content->id,
            'emv_configuration_id' => $configuration->id,
            'formula_version' => $configuration->formula_version,
            'rate_card_version' => $configuration->rate_card_version,
            'currency' => 'EUR',
            'value' => new MetricValue(15.0, MetricTier::Estimated, 'emv'),
            'inputs' => [
                ['metric' => 'views', 'amount' => 1000.0, 'tier' => 'PUBLIC', 'rate' => 0.01, 'rate_source' => 'default', 'included' => true],
            ],
            'calculated_at' => now(),
        ]);

        try {
            $result->update(['currency' => 'USD']);
            $this->fail('emv_results updates must be refused.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->expectException(LogicException::class);

        $result->delete();
    }

    public function test_each_result_snapshots_the_currency_of_its_configuration(): void
    {

        $admin = $this->makeUser(RoleName::Admin);
        $service = app(EmvConfigurationService::class);
        $calculator = app(EmvCalculator::class);
        $content = $this->contentWithViewsAndLikes();

        $v1 = $this->activeConfiguration($admin, 'v1');
        $eurResult = $calculator->calculate($content);
        $this->assertSame('EUR', $eurResult->currency);

        $service->deactivate($v1, $admin);
        $this->activeConfiguration($admin, 'v2', ['currency' => 'USD']);

        $usdResult = $calculator->calculate($content)->fresh();

        $this->assertSame('USD', $usdResult->currency);
        $this->assertSame('EUR', $eurResult->fresh()->currency, 'The historical row keeps its own currency snapshot.');
    }
}
