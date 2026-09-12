<?php

namespace Database\Factories;

use App\Models\FighterPosition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FighterPosition>
 */
class FighterPositionFactory extends Factory
{
    /**
     * @var class-string<FighterPosition>
     */
    protected $model = FighterPosition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'x' => $this->faker->randomFloat(4, 0.02, 0.98),
            'y' => $this->faker->randomFloat(4, 0.02, 0.98),
        ];
    }
}
