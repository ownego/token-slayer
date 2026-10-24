<?php

namespace Database\Factories;

use App\Models\MissingModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MissingModel>
 */
class MissingModelFactory extends Factory
{
    /**
     * A model first and last seen just now.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'model' => 'claude-sonnet-5-5',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
