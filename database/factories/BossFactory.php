<?php

namespace Database\Factories;

use App\Models\Boss;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Boss>
 */
class BossFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = $this->faker->unique()->numberBetween(1, 1_000_000);

        return [
            'number' => $number,
            'max_hp' => fn (array $attributes) => $attributes['number'] * config('game.base_hp'),
            'current_hp' => fn (array $attributes) => $attributes['max_hp'],
            'status' => 'alive',
            'spawned_at' => now(),
        ];
    }

    public function defeated(): static
    {
        return $this->state(fn () => [
            'status' => 'defeated',
            'current_hp' => 0,
            'defeated_at' => now(),
        ]);
    }
}
