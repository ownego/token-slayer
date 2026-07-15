<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'slack_user_id' => 'U'.fake()->unique()->bothify('#########'),
            'slack_handle' => fake()->userName(),
            'display_name' => fake()->name(),
            'avatar_url' => 'https://avatars.example/'.fake()->uuid().'.png',
            'hook_token' => hash('sha256', Str::random(48)),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Create the user with the super_admin role, replacing the retired
     * is_admin boolean flag used by every existing admin-gated test.
     *
     * @return static
     */
    public function admin(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole('super_admin'));
    }
}
