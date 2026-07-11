<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountUsageSnapshot>
 */
class AccountUsageSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $util5h = fake()->numberBetween(0, 100);
        $util7d = fake()->numberBetween(0, 100);
        $util7dSonnet = fake()->numberBetween(0, $util7d);
        $util7dOi = fake()->numberBetween(0, $util7d);

        return [
            'account_id' => Account::factory(),
            'util_5h' => $util5h,
            'util_7d' => $util7d,
            'util_7d_sonnet' => $util7dSonnet,
            'util_7d_oi' => $util7dOi,
            'reset_5h_at' => now()->addHours(5),
            'reset_7d_at' => now()->addDays(7),
            'raw' => [
                'five_hour' => ['utilization' => $util5h, 'resets_at' => now()->addHours(5)->toIso8601String()],
                'seven_day' => ['utilization' => $util7d, 'resets_at' => now()->addDays(7)->toIso8601String()],
                'seven_day_sonnet' => ['utilization' => $util7dSonnet],
                'seven_day_opus' => ['utilization' => $util7dOi],
            ],
            'created_at' => now(),
        ];
    }
}
