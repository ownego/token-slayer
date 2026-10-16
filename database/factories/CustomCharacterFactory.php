<?php

namespace Database\Factories;

use App\Models\CustomCharacter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomCharacter>
 */
class CustomCharacterFactory extends Factory
{
    /**
     * A fighter always has a history row for the version in use.
     *
     * @return static
     */
    public function configure(): static
    {
        return $this->afterCreating(function (CustomCharacter $character): void {
            $character->versions()->firstOrCreate(
                ['version_hash' => $character->version_hash],
                ['animations' => $character->animations],
            );
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Custom',
            'version_hash' => fake()->regexify('[a-f0-9]{12}'),
            'active' => true,
            'animations' => [
                'idle' => ['frames' => 6, 'rate' => 8],
                'walk' => ['frames' => 8, 'rate' => 10],
                'attack' => ['frames' => 6, 'rate' => 12],
                'death' => ['frames' => 3, 'rate' => 6],
            ],
        ];
    }
}
