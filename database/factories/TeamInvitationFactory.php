<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Billing\Models\TeamInvitation;
use App\Shared\Enums\RoleName;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeamInvitation>
 *
 * The plaintext token is never stored — tests that need to drive the
 * acceptance flow should mint one and pass its hash:
 *
 *   $token = Str::random(64);
 *   TeamInvitation::factory()->create(['token_hash' => TeamInvitation::hashToken($token)]);
 */
class TeamInvitationFactory extends Factory
{
    use ResolvesTenant;

    protected $model = TeamInvitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'email' => fake()->unique()->safeEmail(),
            'role' => RoleName::Analyst,
            'token_hash' => TeamInvitation::hashToken(Str::random(64)),
            'invited_by_user_id' => User::factory(),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'revoked_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()->subHour()]);
    }

    public function role(RoleName $role): static
    {
        return $this->state(['role' => $role]);
    }
}
