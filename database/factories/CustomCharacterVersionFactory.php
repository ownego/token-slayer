<?php

namespace Database\Factories;

use App\Models\CustomCharacterVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An earlier version of a player's fighter: a row the player can switch back to.
 *
 * @extends Factory<CustomCharacterVersion>
 */
class CustomCharacterVersionFactory extends Factory
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
            'animations' => [
                'idle' => ['frames' => 6, 'rate' => 8],
                'walk' => ['frames' => 8, 'rate' => 10],
                'attack' => ['frames' => 6, 'rate' => 12],
                'death' => ['frames' => 3, 'rate' => 6],
            ],
        ];
    }
}
