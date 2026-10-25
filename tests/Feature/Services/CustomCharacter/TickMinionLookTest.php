<?php

use App\Events\FighterMinionsChanged;
use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\MinionLookUnknownException;
use App\Models\CustomMinion;
use App\Models\User;
use App\Services\CustomCharacter\TickMinionLook;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([FighterMinionsChanged::class]);
});

it('unticks a default and bumps the revision', function () {
    $user = User::factory()->create();

    app(TickMinionLook::class)->execute($user, 'default:demon-a', false);

    $fresh = $user->fresh();
    expect($fresh->minion_defaults_off)->toBe(['demon-a'])
        ->and($fresh->minion_pool_rev)->toBe(1)
        ->and($user->minion_pool_rev)->toBe(1)
        ->and($fresh->minionPoolState()['defaults'])->not->toContain('demon-a');
});

it('ticks a default back', function () {
    $user = User::factory()->create(['minion_defaults_off' => ['demon-a', 'smw-goomba'], 'minion_pool_rev' => 4]);

    app(TickMinionLook::class)->execute($user, 'default:demon-a', true);

    $fresh = $user->fresh();
    expect($fresh->minion_defaults_off)->toBe(['smw-goomba'])
        ->and($fresh->minion_pool_rev)->toBe(5);
});

it('unticks a custom minion by slot', function () {
    $user = User::factory()->create();
    $first = CustomMinion::factory()->for($user)->create(['slot' => 1]);
    $second = CustomMinion::factory()->for($user)->create(['slot' => 2]);

    app(TickMinionLook::class)->execute($user, 'slot:2', false);

    expect($first->fresh()->enabled)->toBeTrue()
        ->and($second->fresh()->enabled)->toBeFalse()
        ->and($user->fresh()->minion_pool_rev)->toBe(1)
        ->and($user->fresh()->minion_defaults_off)->toBeNull();
});

it('refuses an unknown default key', function (string $look) {
    $user = User::factory()->create(['minion_pool_rev' => 2]);

    expect(fn () => app(TickMinionLook::class)->execute($user, $look, false))
        ->toThrow(MinionLookUnknownException::class);

    expect($user->fresh()->minion_defaults_off)->toBeNull()
        ->and($user->fresh()->minion_pool_rev)->toBe(2);
    Event::assertNotDispatched(FighterMinionsChanged::class);
})->with([
    'a key that is not a default' => ['default:evil'],
    'an empty key' => ['default:'],
    'neither a default nor a slot' => ['custom:1'],
    'a slot that is not a number' => ['slot:abc'],
    'a slot of zero' => ['slot:0'],
]);

it('refuses a slot the player does not hold', function () {
    $user = User::factory()->create(['minion_pool_rev' => 2]);
    $mine = CustomMinion::factory()->for($user)->create(['slot' => 1]);
    $theirs = CustomMinion::factory()->for(User::factory()->create())->create(['slot' => 2]);

    foreach (['slot:9', 'slot:2'] as $look) {
        expect(fn () => app(TickMinionLook::class)->execute($user, $look, false))
            ->toThrow(MinionLookUnknownException::class);
    }

    expect($mine->fresh()->enabled)->toBeTrue()
        ->and($theirs->fresh()->enabled)->toBeTrue()
        ->and($user->fresh()->minion_pool_rev)->toBe(2)
        ->and($theirs->user->fresh()->minion_pool_rev)->toBe(0);
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

it('does nothing when the tick already matches', function () {
    $user = User::factory()->create(['minion_defaults_off' => ['demon-a']]);
    CustomMinion::factory()->for($user)->create(['slot' => 1, 'enabled' => false]);

    $tick = app(TickMinionLook::class);
    $tick->execute($user, 'default:demon-a', false);
    $tick->execute($user, 'default:smw-goomba', true);
    $tick->execute($user, 'slot:1', false);

    expect($user->fresh()->minion_pool_rev)->toBe(0)
        ->and($user->fresh()->minion_defaults_off)->toBe(['demon-a']);
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

it('broadcasts the new pool', function () {
    $user = User::factory()->create();

    app(TickMinionLook::class)->execute($user, 'default:smw-bowser', false);

    Event::assertDispatched(
        FighterMinionsChanged::class,
        fn (FighterMinionsChanged $event) => $event->user->is($user)
            && $event->user->minion_pool_rev === 1
            && $event->user->minionPoolState()['defaults'] === ['demon-a', 'blood-monster-a', 'smw-goomba', 'smw-babybowser'],
    );
});

it('waits for the player lock', function () {
    $user = User::factory()->create();
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    $held = Cache::lock(CacheKeys::customCharacterBuild($user->id), 30);
    $held->get();

    expect(fn () => app(TickMinionLook::class)->execute($user, 'default:demon-a', false))
        ->toThrow(CustomCharacterBusyException::class);

    expect($user->fresh()->minion_pool_rev)->toBe(0);
    Event::assertNotDispatched(FighterMinionsChanged::class);

    $held->release();
});
