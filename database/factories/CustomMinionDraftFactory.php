<?php

namespace Database\Factories;

use App\Enums\CustomMinionSource;
use App\Models\CustomMinionDraft;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A built minion waiting to be added: no slot (it adds a new minion) and no name
 * (an upload). A draft that replaces a minion takes `slot`.
 *
 * @extends Factory<CustomMinionDraft>
 */
class CustomMinionDraftFactory extends Factory
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
            'slot' => null,
            'name' => null,
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
     * A draft copied from the player's custom fighter, carrying the fighter's name.
     *
     * @param  string  $name  the fighter's name
     * @return static
     */
    public function fromFighter(string $name = 'Custom'): static
    {
        return $this->state(['source' => CustomMinionSource::Fighter, 'name' => $name]);
    }
}
