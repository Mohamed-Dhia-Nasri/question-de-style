<?php

namespace Database\Factories;

use App\Models\User;
use App\Shared\Enums\RoleName;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Synthetic data only — factories and seeders must never contain real
 * personal data (DP-005 / personal-data governance).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'display_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }

    /** Assign the user's single canonical role (ENUM-RoleName). */
    public function withRole(RoleName $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncRoles([$role->value]));
    }
}
