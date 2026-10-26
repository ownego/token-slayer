<?php

use App\Events\FighterMinionsChanged;
use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\MinionLookUnknownException;
use App\Exceptions\MinionPoolChangedException;
use App\Exceptions\PlayerFacingException;
use App\Models\CustomMinion;
use App\Models\User;
use App\Services\CustomCharacter\ApplyMinionPool;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([FighterMinionsChanged::class]);
});

/**
 * What the Minions tab sends on Save for a player: every default look, and every minion the
 * tab drew with the hash it drew.
 *
 * @param  User  $user  the player
 * @param  list<string>  $defaultsOff  the default keys left out
 * @param  list<int>  $slotsOff  the slots left out
 * @return array{0: array<string, bool>, 1: array<int, array{hash: string, ticked: bool}>}
 */
function savedChoice(User $user, array $defaultsOff = [], array $slotsOff = []): array
{
    $defaults = [];

    foreach (config('token_slayer.custom_character.default_minions') as $key) {
        $defaults[$key] = ! in_array($key, $defaultsOff, true);
    }

    $slots = [];

    foreach (CustomMinion::query()->where('user_id', $user->id)->orderBy('slot')->get() as $minion) {
        $slots[$minion->slot] = ['hash' => $minion->version_hash, 'ticked' => ! in_array($minion->slot, $slotsOff, true)];
    }

    return [$defaults, $slots];
}

/**
 * The pool column of a player, read straight from the database.
 *
 * @param  User  $user  the player
 * @return array{rev: int, defaults_off: list<string>, slots_off: list<int>}|null
 */
function storedPoolOf(User $user): ?array
{
    $raw = DB::table('users')->where('id', $user->id)->value('minion_pool');

    return $raw === null ? null : json_decode($raw, true);
}

it('stores the whole choice in one write, one revision step and one broadcast', function () {
    $user = User::factory()->create();
    CustomMinion::factory()->for($user)->create(['slot' => 1]);
    CustomMinion::factory()->for($user)->create(['slot' => 2]);

    $written = app(ApplyMinionPool::class)->execute($user, ...savedChoice($user, ['demon-a', 'smw-bowser'], [2]));

    expect($written)->toBeTrue()
        ->and(storedPoolOf($user))->toBe(['rev' => 1, 'defaults_off' => ['demon-a', 'smw-bowser'], 'slots_off' => [2]]);
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 1);
    Event::assertDispatched(FighterMinionsChanged::class, fn (FighterMinionsChanged $event) => $event->user->is($user)
        && $event->user->minionPoolState()['rev'] === 1
        && $event->user->minionPoolState()['defaults'] === ['blood-monster-a', 'smw-goomba', 'smw-babybowser']
        && count($event->user->minionPoolState()['custom']) === 1);
});

it('moves the revision up by exactly one however many looks changed', function () {
    $user = User::factory()->create(['minion_pool' => ['rev' => 6, 'defaults_off' => ['demon-a'], 'slots_off' => []]]);

    app(ApplyMinionPool::class)->execute($user, ...savedChoice($user, ['smw-goomba', 'smw-bowser', 'blood-monster-a']));

    expect(storedPoolOf($user)['rev'])->toBe(7);
});

it('writes nothing, bumps nothing and broadcasts nothing when the choice is the one already stored', function (?array $stored) {
    $user = User::factory()->create(['minion_pool' => $stored]);
    CustomMinion::factory()->for($user)->create(['slot' => 1]);

    $written = app(ApplyMinionPool::class)->execute($user, ...savedChoice($user, $stored['defaults_off'] ?? [], $stored['slots_off'] ?? []));

    expect($written)->toBeFalse()
        ->and(storedPoolOf($user))->toBe($stored);
    Event::assertNotDispatched(FighterMinionsChanged::class);
})->with([
    'a player who never touched a tick' => [null],
    'a player with a stored choice' => [['rev' => 3, 'defaults_off' => ['demon-a'], 'slots_off' => [1]]],
]);

it('does not count a slot nobody holds any more as a difference', function () {
    $user = User::factory()->create(['minion_pool' => ['rev' => 2, 'defaults_off' => [], 'slots_off' => [3]]]);

    $written = app(ApplyMinionPool::class)->execute($user, ...savedChoice($user));

    expect($written)->toBeFalse()
        ->and(storedPoolOf($user)['rev'])->toBe(2);
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

it('refuses a default key that is not a default look, or a choice that leaves one out', function (callable $mangle) {
    $user = User::factory()->create(['minion_pool' => ['rev' => 2, 'defaults_off' => [], 'slots_off' => []]]);
    [$defaults, $slots] = savedChoice($user);

    expect(fn () => app(ApplyMinionPool::class)->execute($user, $mangle($defaults), $slots))
        ->toThrow(MinionLookUnknownException::class);

    expect(storedPoolOf($user)['rev'])->toBe(2);
    Event::assertNotDispatched(FighterMinionsChanged::class);
})->with([
    'an invented key' => [fn (array $defaults): array => [...$defaults, 'evil' => false]],
    'a default left out of the choice' => [function (array $defaults): array {
        unset($defaults['demon-a']);

        return $defaults;
    }],
    'only an invented key' => [fn (array $defaults): array => ['evil' => true]],
]);

it('refuses a choice drawn from minions the player no longer holds, and writes nothing', function (string $what) {
    $user = User::factory()->create(['minion_pool' => ['rev' => 2, 'defaults_off' => [], 'slots_off' => []]]);
    $one = CustomMinion::factory()->for($user)->create(['slot' => 1]);
    $two = CustomMinion::factory()->for($user)->create(['slot' => 2]);
    [$defaults, $slots] = savedChoice($user, ['demon-a'], [2]);

    // another tab changes the set after this one drew its cells
    match ($what) {
        'replaced' => $two->update(['version_hash' => 'f00dfeed1234']),
        'removed' => $two->delete(),
        'added' => CustomMinion::factory()->for($user)->create(['slot' => 3]),
    };

    expect(fn () => app(ApplyMinionPool::class)->execute($user, $defaults, $slots))
        ->toThrow(MinionPoolChangedException::class);

    expect(storedPoolOf($user))->toBe(['rev' => 2, 'defaults_off' => [], 'slots_off' => []]);
    Event::assertNotDispatched(FighterMinionsChanged::class);
})->with(['a minion replaced in another tab' => 'replaced', 'a minion removed in another tab' => 'removed', 'a minion added in another tab' => 'added']);

it('turns a forged hash away', function () {
    $user = User::factory()->create();
    CustomMinion::factory()->for($user)->create(['slot' => 1]);
    [$defaults, $slots] = savedChoice($user);
    $slots[1]['hash'] = 'not-the-hash';

    expect(fn () => app(ApplyMinionPool::class)->execute($user, $defaults, $slots))
        ->toThrow(MinionPoolChangedException::class);
    expect(storedPoolOf($user))->toBeNull();
});

it('tells the player what to do, in a PlayerFacingException', function () {
    $exception = new MinionPoolChangedException;

    expect($exception)->toBeInstanceOf(PlayerFacingException::class)
        ->and($exception->getMessage())->toBe('Your minions changed in another tab. Check the pool, then try again.');
});

it('saves what it was sent even though the model in hand was loaded before another tab saved', function () {
    $user = User::factory()->create();
    $stale = User::find($user->id);

    // tab B unticks Demon and saves
    app(ApplyMinionPool::class)->execute($user, ...savedChoice($user, ['demon-a']));
    expect(storedPoolOf($user))->toBe(['rev' => 1, 'defaults_off' => ['demon-a'], 'slots_off' => []]);

    // tab A, loaded before that, ticks it back and saves: its choice equals what its own model remembers
    $written = app(ApplyMinionPool::class)->execute($stale, ...savedChoice($stale));

    expect($written)->toBeTrue()
        ->and(storedPoolOf($user))->toBe(['rev' => 2, 'defaults_off' => [], 'slots_off' => []])
        ->and($user->fresh()->minionPoolState()['defaults'])->toBe(config('token_slayer.custom_character.default_minions'));
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 2);
    Event::assertDispatched(FighterMinionsChanged::class, fn (FighterMinionsChanged $event) => $event->user->minionPoolState()['rev'] === 2
        && in_array('demon-a', $event->user->minionPoolState()['defaults'], true));
});

it('moves the revision only when the database changes, whichever tab was first', function () {
    $user = User::factory()->create();
    $stale = User::find($user->id);
    $apply = app(ApplyMinionPool::class);

    $apply->execute($user, ...savedChoice($user, ['demon-a']));
    $again = $apply->execute($stale, ...savedChoice($stale, ['demon-a']));

    expect($again)->toBeFalse()
        ->and(storedPoolOf($user)['rev'])->toBe(1);
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 1);
});

it('announces the revision the database holds, not the one a model carried', function () {
    $user = User::factory()->create(['minion_pool' => ['rev' => 4, 'defaults_off' => [], 'slots_off' => []]]);
    $stale = User::find($user->id);
    User::whereKey($user->id)->update(['minion_pool' => json_encode(['rev' => 8, 'defaults_off' => [], 'slots_off' => []])]);

    app(ApplyMinionPool::class)->execute($stale, ...savedChoice($stale, ['demon-a']));

    expect(storedPoolOf($user)['rev'])->toBe(9);
    Event::assertDispatched(FighterMinionsChanged::class, fn (FighterMinionsChanged $event) => $event->user->minionPoolState()['rev'] === 9);
});

it('leaves the minion rows alone: the tick is not their business', function () {
    $user = User::factory()->create();
    $minion = CustomMinion::factory()->for($user)->create(['slot' => 1]);

    app(ApplyMinionPool::class)->execute($user, ...savedChoice($user, [], [1]));

    expect($minion->fresh()->enabled)->toBeTrue()
        ->and(storedPoolOf($user)['slots_off'])->toBe([1]);
});

it('waits for the player lock and writes nothing while another change holds it', function () {
    $user = User::factory()->create();
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    $held = Cache::lock(CacheKeys::customCharacterBuild($user->id), 30);
    $held->get();

    expect(fn () => app(ApplyMinionPool::class)->execute($user, ...savedChoice($user, ['demon-a'])))
        ->toThrow(CustomCharacterBusyException::class);

    expect(storedPoolOf($user))->toBeNull();
    Event::assertNotDispatched(FighterMinionsChanged::class);

    $held->release();
});
