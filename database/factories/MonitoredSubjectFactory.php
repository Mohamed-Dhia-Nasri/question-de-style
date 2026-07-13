<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Creator;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). Default is the v1 roster shape
 * (ADR-0011): a CREATOR subject referencing a tracked creator. Open-web
 * term subjects are deferred (DEF-006) and have no factory state.
 *
 * @extends Factory<MonitoredSubject>
 */
class MonitoredSubjectFactory extends Factory
{
    use ResolvesTenant;

    protected $model = MonitoredSubject::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'subject_type' => MonitoredSubjectType::Creator,
            'label' => fake()->name(),
            'creator_id' => Creator::factory(),
            'terms' => null,
            'platforms' => [Platform::Instagram, Platform::TikTok],
            'campaign_id' => null,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
