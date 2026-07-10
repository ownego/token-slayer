<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'max-20x',
        ];
    }

    /**
     * Account with a live server-side OAuth grant.
     *
     * @return static
     */
    public function connected(): static
    {
        return $this->state(fn (): array => [
            'account_uuid' => fake()->uuid(),
            'organization_uuid' => fake()->uuid(),
            'oauth_access_token' => 'sk-ant-oat01-'.Str::random(24),
            'oauth_refresh_token' => 'sk-ant-ort01-'.Str::random(24),
            'oauth_expires_at' => now()->addHours(8),
            'status' => Account::STATUS_ACTIVE,
        ]);
    }

    /**
     * Connected account whose refresh token has been revoked.
     *
     * @return static
     */
    public function needsReauth(): static
    {
        return $this->connected()->state(fn (): array => [
            'status' => Account::STATUS_NEEDS_REAUTH,
            'probe_error' => 'refresh token expired',
        ]);
    }
}
