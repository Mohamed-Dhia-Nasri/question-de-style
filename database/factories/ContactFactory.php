<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Contact;
use App\Modules\CRM\Models\Creator;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005) — never real personal data. ENT-Contact is
 * manual entry only (REQ-M3-002); rows must stay hard-deletable.
 *
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    use ResolvesTenant;

    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'creator_id' => Creator::factory(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'postal_address' => fake()->address(),
            'preferred_channel' => 'email',
        ];
    }
}
