<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'device_id' => 'fp-'.Str::random(16),
        ];
    }

    /**
     * The legacy `'default'` sentinel device.
     *
     * @return static
     */
    public function legacyDefault(): static
    {
        return $this->state(fn (): array => ['device_id' => Device::DEFAULT_NAME]);
    }

    /**
     * An admin-opened placeholder awaiting its first contact.
     *
     * @return static
     */
    public function placeholder(): static
    {
        return $this->state(fn (): array => ['device_id' => null]);
    }
}
