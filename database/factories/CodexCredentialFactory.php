<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\CodexCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CodexCredential>
 */
class CodexCredentialFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chatgpt_account_id' => fake()->unique()->uuid(),
            'chatgpt_user_id' => fake()->uuid(),
            'plan_type' => 'pro',
            'status' => AccountStatus::Active,
        ];
    }
}
