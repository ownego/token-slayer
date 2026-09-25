<?php

namespace Database\Factories;

use App\Enums\BossCharacter;
use App\Models\Boss;
use App\Services\BossNameGenerator;
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
            'name' => fn () => app(BossNameGenerator::class)->next(),
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

    /**
     * A ThaNode: the recognizable character is identified by its name.
     *
     * @return static
     */
    public function thanode(): static
    {
        return $this->state(fn () => ['name' => BossCharacter::Thanos->fixedName()]);
    }
}
