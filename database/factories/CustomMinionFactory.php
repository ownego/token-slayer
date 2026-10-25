<?php

namespace Database\Factories;

use App\Enums\CustomMinionSource;
use App\Models\CustomMinion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A minion holding slot 1, named after its slot. A player with several minions
 * needs each in its own slot: `->count(3)->sequence(fn ($s) => ['slot' => $s->index + 1])`.
 *
 * @extends Factory<CustomMinion>
 */
class CustomMinionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'version_hash' => fake()->regexify('[a-f0-9]{12}'),
            'slot' => 1,
            'enabled' => true,
            'name' => fn (array $attributes): string => "Minion {$attributes['slot']}",
            'animations' => [
                'idle' => ['frames' => 6, 'rate' => 8],
                'walk' => ['frames' => 8, 'rate' => 10],
                'attack' => ['frames' => 6, 'rate' => 12],
                'death' => ['frames' => 3, 'rate' => 6],
            ],
            'body_px' => 22,
            'source' => CustomMinionSource::Upload,
        ];
    }

    /**
     * A minion copied from the player's custom fighter.
     *
     * @return static
     */
    public function fromFighter(): static
    {
        return $this->state(['source' => CustomMinionSource::Fighter]);
    }
}
