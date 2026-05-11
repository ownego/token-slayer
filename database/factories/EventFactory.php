<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'claude-code',
            'event_type' => 'stop',
            'tokens' => $this->faker->numberBetween(1_000, 80_000),
            'session_id' => (string) Str::uuid(),
            'raw_payload' => ['hook_event_name' => 'Stop'],
        ];
    }
}
