<?php

use App\Models\CustomMinion;
use App\Models\User;
use App\Services\CustomCharacter\MinionPool;
use App\Services\CustomCharacter\MinionPoolSelection;
use App\Services\CustomCharacter\MinionPoolWriter;
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

/**
 * The stored shape of a player's selection.
 *
 * @param  int  $rev  the pool revision
 * @param  list<string>  $defaultsOff  the default keys left out
 * @param  list<int>  $slotsOff  the slots left out
 * @return array{minion_pool: array{rev: int, defaults_off: list<string>, slots_off: list<int>}}
 */
function storedPool(int $rev = 1, array $defaultsOff = [], array $slotsOff = []): array
{
    return ['minion_pool' => ['rev' => $rev, 'defaults_off' => $defaultsOff, 'slots_off' => $slotsOff]];
}

it('ticks every look for a player who never touched the ticks', function () {
    $user = User::factory()->create();

    expect($user->minion_pool)->toBeNull()
        ->and(app(MinionPool::class)->get($user))->toBe([
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
    $user = User::factory()->create(storedPool(3, ['demon-a', 'smw-bowser'], [2]));
    $ticked = CustomMinion::factory()->for($user)->create(['slot' => 1]);
    CustomMinion::factory()->for($user)->create(['slot' => 2]);

    $pool = app(MinionPool::class)->get($user->fresh());

    expect($pool['defaults'])->toBe(['blood-monster-a', 'smw-goomba', 'smw-babybowser'])
        ->and($pool['custom'])->toBe([$ticked->broadcastState()]);
});

it('does not read the minion row\'s enabled flag any more', function () {
    $user = User::factory()->create();
    $minion = CustomMinion::factory()->for($user)->create(['slot' => 1, 'enabled' => false]);

    expect(app(MinionPool::class)->get($user->fresh())['custom'])->toBe([$minion->broadcastState()]);
});

it('falls back to all five defaults when nothing is ticked', function (bool $withCustom) {
    $user = User::factory()->create(storedPool(2, poolDefaultLooks(), $withCustom ? [1] : []));

    if ($withCustom) {
        CustomMinion::factory()->for($user)->create(['slot' => 1]);
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
    $user = User::factory()->create(storedPool(2, poolDefaultLooks()));
    $minion = CustomMinion::factory()->for($user)->create(['slot' => 1]);

    expect(app(MinionPool::class)->get($user->fresh()))->toMatchArray([
        'defaults' => [],
        'custom' => [$minion->broadcastState()],
    ]);
});

it('ignores a stored key that is no longer a default', function () {
    $user = User::factory()->create(storedPool(1, ['retired-look', 'smw-goomba']));

    expect(app(MinionPool::class)->get($user)['defaults'])
        ->toBe(['demon-a', 'blood-monster-a', 'smw-babybowser', 'smw-bowser'])
        ->and(MinionPoolSelection::of($user)->defaultsOff)->toBe(['smw-goomba']);
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
    $user = User::factory()->create(storedPool(7));

    expect(app(MinionPool::class)->get($user)['rev'])->toBe(7)
        ->and($user->minionPoolState()['rev'])->toBe(7);
});

it('reads a stored selection forgivingly: repeats once, in config and slot order, nonsense as untouched', function () {
    $selection = MinionPoolSelection::fromColumn([
        'rev' => 5,
        'defaults_off' => ['smw-bowser', 'demon-a', 'demon-a', 'evil'],
        'slots_off' => [3, '1', 3, 0, -2, 'x'],
    ]);

    expect($selection->rev)->toBe(5)
        ->and($selection->defaultsOff)->toBe(['demon-a', 'smw-bowser'])
        ->and($selection->slotsOff)->toBe([1, 3]);

    foreach ([null, 'text', 4, []] as $nonsense) {
        expect(MinionPoolSelection::fromColumn($nonsense)->toColumn())->toBe(['rev' => 0, 'defaults_off' => [], 'slots_off' => []]);
    }
});

it('treats a slot nobody left out as ticked, and the selection as empty only when nothing is in the pool', function () {
    $selection = MinionPoolSelection::fromColumn(['rev' => 1, 'defaults_off' => poolDefaultLooks(), 'slots_off' => [1]]);

    expect($selection->slotTicked(1))->toBeFalse()
        ->and($selection->slotTicked(2))->toBeTrue()
        ->and($selection->isEmpty([1]))->toBeTrue()
        ->and($selection->isEmpty([1, 2]))->toBeFalse()
        ->and($selection->isEmpty([]))->toBeTrue();
});

describe('the writer', function () {
    it('writes the whole selection as one update and moves the revision up by one', function () {
        $user = User::factory()->create(storedPool(4, ['demon-a'], [2]));

        $written = app(MinionPoolWriter::class)->write($user->id, fn (MinionPoolSelection $stored) => $stored->withDefault('smw-goomba', false)->withSlot(2, true));

        expect($written)->toBeTrue()
            ->and($user->fresh()->minion_pool)->toBe(['rev' => 5, 'defaults_off' => ['demon-a', 'smw-goomba'], 'slots_off' => []]);
    });

    it('starts the revision at 1 for a player who never touched the pool', function () {
        $user = User::factory()->create();

        app(MinionPoolWriter::class)->write($user->id, fn (MinionPoolSelection $stored) => $stored->withDefault('demon-a', false));

        expect($user->fresh()->minion_pool['rev'])->toBe(1);
    });

    it('writes nothing, and keeps the revision, when the change says there is none', function () {
        $user = User::factory()->create(storedPool(4, ['demon-a']));

        $written = app(MinionPoolWriter::class)->write($user->id, fn (MinionPoolSelection $stored) => null);

        expect($written)->toBeFalse()
            ->and($user->fresh()->minion_pool['rev'])->toBe(4);
    });

    it('works from the stored column, not from the model the caller holds', function () {
        $user = User::factory()->create(storedPool(2, ['demon-a']));
        $stale = User::find($user->id);
        User::whereKey($user->id)->update(['minion_pool' => json_encode(['rev' => 9, 'defaults_off' => ['smw-bowser'], 'slots_off' => []])]);

        app(MinionPoolWriter::class)->write($stale->id, fn (MinionPoolSelection $stored) => $stored->withDefault('demon-a', false));

        expect($user->fresh()->minion_pool)->toBe(['rev' => 10, 'defaults_off' => ['demon-a', 'smw-bowser'], 'slots_off' => []]);
    });
});
