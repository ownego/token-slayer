<?php

namespace Database\Factories;

use App\Enums\GrantStatus;
use App\Models\AccountProvisionedGrant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountProvisionedGrant>
 */
class AccountProvisionedGrantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => AccountFactory::new(),
            'device_id' => DeviceFactory::new(),
            'status' => GrantStatus::Pending,
            'token_uuid' => fake()->uuid(),
            'provisioned_at' => now(),
        ];
    }

    /**
     * A freshly-provisioned grant no machine has pulled yet.
     *
     * @return static
     */
    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => GrantStatus::Pending, 'claimed_at' => null]);
    }

    /**
     * A grant a machine has pulled.
     *
     * @return static
     */
    public function claimed(): static
    {
        return $this->state(fn (): array => ['status' => GrantStatus::Claimed, 'claimed_at' => now()]);
    }

    /**
     * A revoked grant.
     *
     * @return static
     */
    public function revoked(): static
    {
        return $this->state(fn (): array => ['status' => GrantStatus::Revoked, 'revoked_at' => now()]);
    }
}
