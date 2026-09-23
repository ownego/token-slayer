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
            // Same rule as BossArena::spawn(): a recognizable character keeps its
            // fixed name, anything else draws from the pool. Kept DB-free (next(),
            // not nextForSpawn()) so make() works without a database.
            'name' => fn (array $attributes) => BossCharacter::forNumber($attributes['number'])->fixedName()
                ?? app(BossNameGenerator::class)->next(),
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
