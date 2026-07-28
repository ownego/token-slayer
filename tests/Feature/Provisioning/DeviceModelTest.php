<?php

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('belongs to a user and enforces unique (user_id, device_id)', function () {
    $user = User::factory()->create();
    Device::factory()->for($user)->create(['device_id' => 'fp-abc']);

    expect($user->devices)->toHaveCount(1)
        ->and($user->devices->first()->device_id)->toBe('fp-abc');

    Device::factory()->for($user)->create(['device_id' => 'fp-abc']);
})->throws(QueryException::class);

it('allows multiple placeholders (NULL device_id) for one user', function () {
    $user = User::factory()->create();
    Device::factory()->for($user)->placeholder()->create();
    Device::factory()->for($user)->placeholder()->create();

    expect($user->devices()->whereNull('device_id')->count())->toBe(2);
});

it('exposes the legacy default sentinel via factory state', function () {
    $device = Device::factory()->legacyDefault()->create();

    expect($device->device_id)->toBe(Device::DEFAULT_NAME);
});

it('exposes a human-readable name via the named factory state', function () {
    $device = Device::factory()->named('Work laptop')->create();

    expect($device->name)->toBe('Work laptop');
});
