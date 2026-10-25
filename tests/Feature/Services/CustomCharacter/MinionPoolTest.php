<?php

use App\Models\CustomMinion;
use App\Models\User;
use App\Services\CustomCharacter\MinionPool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The five default looks, in the order the config lists them.
 *
 * @return list<string>
 */
function poolDefaultLooks(): array
{
    return config('token_slayer.custom_character.default_minions');
}

it('ticks every look for a player who never touched the ticks', function () {
    $user = User::factory()->create();

    expect(app(MinionPool::class)->get($user))->toBe([
        'rev' => 0,
        'defaults' => poolDefaultLooks(),
        'custom' => [],
    ])->and(poolDefaultLooks())->toHaveCount(5);
});

it('keeps the defaults when the first custom minion is added', function () {
    $user = User::factory()->create();
    $minion = CustomMinion::factory()->for($user)->create(['slot' => 1]);

    $pool = app(MinionPool::class)->get($user->fresh());

    expect($pool['defaults'])->toBe(poolDefaultLooks())
        ->and($pool['custom'])->toBe([$minion->broadcastState()]);
});

it('leaves out unticked defaults and unticked customs', function () {
    $user = User::factory()->create(['minion_defaults_off' => ['demon-a', 'smw-bowser']]);
    $ticked = CustomMinion::factory()->for($user)->create(['slot' => 1]);
    CustomMinion::factory()->for($user)->create(['slot' => 2, 'enabled' => false]);

    $pool = app(MinionPool::class)->get($user->fresh());

    expect($pool['defaults'])->toBe(['blood-monster-a', 'smw-goomba', 'smw-babybowser'])
        ->and($pool['custom'])->toBe([$ticked->broadcastState()]);
});

it('falls back to all five defaults when nothing is ticked', function (bool $withCustom) {
    $user = User::factory()->create(['minion_defaults_off' => poolDefaultLooks()]);

    if ($withCustom) {
        CustomMinion::factory()->for($user)->create(['slot' => 1, 'enabled' => false]);
    }

    expect(app(MinionPool::class)->get($user->fresh()))->toMatchArray([
        'defaults' => poolDefaultLooks(),
        'custom' => [],
    ]);
})->with([
    'no custom minion' => [false],
    'only an unticked custom minion' => [true],
]);

it('keeps only the custom minions when every default is unticked', function () {
    $user = User::factory()->create(['minion_defaults_off' => poolDefaultLooks()]);
    $minion = CustomMinion::factory()->for($user)->create(['slot' => 1]);

    expect(app(MinionPool::class)->get($user->fresh()))->toMatchArray([
        'defaults' => [],
        'custom' => [$minion->broadcastState()],
    ]);
});

it('ignores a stored key that is no longer a default', function () {
    $user = User::factory()->create(['minion_defaults_off' => ['retired-look', 'smw-goomba']]);

    expect(app(MinionPool::class)->get($user)['defaults'])
        ->toBe(['demon-a', 'blood-monster-a', 'smw-babybowser', 'smw-bowser']);
});

it('orders custom minions by slot', function () {
    $user = User::factory()->create();
    $third = CustomMinion::factory()->for($user)->create(['slot' => 3]);
    $first = CustomMinion::factory()->for($user)->create(['slot' => 1]);
    $second = CustomMinion::factory()->for($user)->create(['slot' => 2]);

    expect(app(MinionPool::class)->get($user->fresh())['custom'])->toBe([
        $first->broadcastState(),
        $second->broadcastState(),
        $third->broadcastState(),
    ]);
});

it('carries the pool revision', function () {
    $user = User::factory()->create(['minion_pool_rev' => 7]);

    expect(app(MinionPool::class)->get($user)['rev'])->toBe(7)
        ->and($user->minionPoolState()['rev'])->toBe(7);
});
