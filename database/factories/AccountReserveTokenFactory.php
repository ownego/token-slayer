<?php

namespace Database\Factories;

use App\Models\AccountReserveToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountReserveToken>
 */
class AccountReserveTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => AccountFactory::new(),
            'token_uuid' => fake()->uuid(),
            'access_token' => 'sk-ant-oat01-'.fake()->sha1(),
            'refresh_token' => 'sk-ant-ort01-'.fake()->sha1(),
            'access_expires_at' => now()->addHours(8),
            'session_expires_at' => now()->addDays(25),
            'session_expires_at_estimated' => false,
        ];
    }

    /**
     * A token already assigned to a device.
     *
     * @return static
     */
    public function used(): static
    {
        return $this->state(fn (): array => ['used_at' => now()]);
    }

    /**
     * A token whose session ran out before anyone used it.
     *
     * @return static
     */
    public function expired(): static
    {
        return $this->state(fn (): array => ['session_expires_at' => now()->subDay()]);
    }

    /**
     * A token the admin removed from the pool.
     *
     * @return static
     */
    public function discarded(): static
    {
        return $this->state(fn (): array => ['discarded_at' => now()]);
    }
}
