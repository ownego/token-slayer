<?php

use App\Models\Device;
use App\Models\User;
use App\Services\Provisioning\DeviceClaimResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves a null fingerprint to the default device only', function () {
    $user = User::factory()->create();
    $default = Device::factory()->for($user)->legacyDefault()->create();
    Device::factory()->for($user)->create(['device_id' => 'fp-other']);

    $resolver = app(DeviceClaimResolver::class);

    expect($resolver->resolve($user, null)?->id)->toBe($default->id);
});

it('returns null for a null fingerprint when the user has no default device', function () {
    $user = User::factory()->create();
    Device::factory()->for($user)->create(['device_id' => 'fp-real']);

    expect(app(DeviceClaimResolver::class)->resolve($user, null))->toBeNull();
});

it('matches an exact fingerprint first, before any binding', function () {
    $user = User::factory()->create();
    Device::factory()->for($user)->placeholder()->create();
    $mine = Device::factory()->for($user)->create(['device_id' => 'fp-mine']);

    expect(app(DeviceClaimResolver::class)->resolve($user, 'fp-mine')?->id)->toBe($mine->id);
});

it('binds an unknown fingerprint to the oldest placeholder', function () {
    $user = User::factory()->create();
    $older = Device::factory()->for($user)->placeholder()->create(['created_at' => now()->subDay()]);
    Device::factory()->for($user)->placeholder()->create();

    $resolved = app(DeviceClaimResolver::class)->resolve($user, 'fp-new');

    expect($resolved?->id)->toBe($older->id)
        ->and($resolved->fresh()->device_id)->toBe('fp-new');
});

it('binds an unknown fingerprint to the unbound default when no placeholder exists', function () {
    $user = User::factory()->create();
    $default = Device::factory()->for($user)->legacyDefault()->create();

    $resolved = app(DeviceClaimResolver::class)->resolve($user, 'fp-upgraded');

    expect($resolved?->id)->toBe($default->id)
        ->and($resolved->fresh()->device_id)->toBe('fp-upgraded');
});

it('prefers a placeholder over the unbound default', function () {
    $user = User::factory()->create();
    $default = Device::factory()->for($user)->legacyDefault()->create();
    $placeholder = Device::factory()->for($user)->placeholder()->create();

    $resolved = app(DeviceClaimResolver::class)->resolve($user, 'fp-x');

    expect($resolved?->id)->toBe($placeholder->id)
        ->and($default->fresh()->device_id)->toBe(Device::DEFAULT_NAME);
});

it('returns null for an unknown fingerprint with nothing to bind', function () {
    $user = User::factory()->create();
    Device::factory()->for($user)->create(['device_id' => 'fp-taken']);

    expect(app(DeviceClaimResolver::class)->resolve($user, 'fp-stranger'))->toBeNull();
});

it('never binds across users', function () {
    $other = User::factory()->create();
    Device::factory()->for($other)->placeholder()->create();
    $me = User::factory()->create();

    expect(app(DeviceClaimResolver::class)->resolve($me, 'fp-me'))->toBeNull();
});

it('resolves an already-bound fingerprint idempotently, without consuming another placeholder', function () {
    $user = User::factory()->create();
    $bound = Device::factory()->for($user)->create(['device_id' => 'fp-taken']);
    $placeholder = Device::factory()->for($user)->placeholder()->create();

    $resolver = app(DeviceClaimResolver::class);
    $first = $resolver->resolve($user, 'fp-taken');
    $second = $resolver->resolve($user, 'fp-taken');

    expect($first?->id)->toBe($bound->id)
        ->and($second?->id)->toBe($bound->id)
        ->and($placeholder->fresh()->device_id)->toBeNull();
});
