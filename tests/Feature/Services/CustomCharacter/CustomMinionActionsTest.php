<?php

use App\Enums\CustomMinionOutcome;
use App\Enums\CustomMinionSource;
use App\Events\FighterCharacterChanged;
use App\Events\FighterMinionsChanged;
use App\Exceptions\BlankStripException;
use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterStorageException;
use App\Exceptions\CustomMinionDraftMissingException;
use App\Exceptions\CustomMinionDuplicateException;
use App\Exceptions\CustomMinionMissingException;
use App\Exceptions\CustomMinionNoFighterException;
use App\Exceptions\CustomMinionSetFullException;
use App\Models\CustomCharacter;
use App\Models\CustomMinion;
use App\Models\CustomMinionDraft;
use App\Models\User;
use App\Services\CustomCharacter\ConfirmCustomCharacter;
use App\Services\CustomCharacter\ConfirmCustomMinion;
use App\Services\CustomCharacter\DiscardCustomMinionDraft;
use App\Services\CustomCharacter\MinionPoolSelection;
use App\Services\CustomCharacter\MeasureStripBodyHeight;
use App\Services\CustomCharacter\RemoveCustomCharacter;
use App\Services\CustomCharacter\RemoveCustomMinion;
use App\Services\CustomCharacter\RetargetCustomMinionDraft;
use App\Services\CustomCharacter\StageCustomMinion;
use App\Services\CustomCharacter\StageMinionFromFighter;
use App\Support\CacheKeys;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    Event::fake([FighterMinionsChanged::class, FighterCharacterChanged::class]);
});

/**
 * Holds the player's lock, as a change running in another tab would.
 *
 * @param  User  $user  the player
 * @return Lock the held lock; release it to let the player's changes through
 */
function holdMinionLock(User $user): Lock
{
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    $held = Cache::lock(CacheKeys::customCharacterBuild($user->id), 30);
    $held->get();

    return $held;
}

/**
 * Builds one of the three synthetic sheets and adds it to the player's set, with its files.
 *
 * @param  User  $user  the owner
 * @param  int  $variant  0, 1 or 2: each gives a different minion
 * @return CustomMinion the minion now in the set
 */
function confirmedMinionFor(User $user, int $variant = 0): CustomMinion
{
    return app(ConfirmCustomMinion::class)->execute($user, stagedMinionFor($user, $variant)->version_hash)->minion;
}

/**
 * Gives the player a full set of minions that have no files (only the rows matter).
 *
 * @param  User  $user  the owner
 * @return void
 */
function fillMinionSet(User $user): void
{
    CustomMinion::factory()->count(3)->sequence(fn ($sequence) => ['slot' => $sequence->index + 1])->create(['user_id' => $user->id]);
}

/**
 * Gives the player a custom fighter built from the plain synthetic sheet, with its files.
 *
 * @param  User  $user  the owner
 * @return CustomCharacter the fighter in use
 */
function fighterWithFiles(User $user): CustomCharacter
{
    return app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user)->version_hash);
}

/**
 * The player's pool revision, read from the database.
 *
 * @param  User  $user  the player
 * @return int
 */
function poolRev(User $user): int
{
    return MinionPoolSelection::of(User::find($user->id))->rev;
}

/**
 * Leaves the slots out of the player's pool, as ticks saved earlier would have, keeping the revision.
 *
 * @param  User  $user  the player
 * @param  list<int>  $slots  the slots to leave out
 * @return void
 */
function leaveSlotsOut(User $user, array $slots): void
{
    $selection = MinionPoolSelection::of(User::find($user->id));
    User::whereKey($user->id)->update(['minion_pool' => json_encode($selection->withChoice($selection->defaultsOff, $slots)->toColumn())]);
}

/**
 * The slots the player has left out of their pool, read from the database.
 *
 * @param  User  $user  the player
 * @return list<int>
 */
function slotsLeftOut(User $user): array
{
    return MinionPoolSelection::of(User::find($user->id))->slotsOff;
}

test('builds a minion draft with its measured body height', function () {
    $user = User::factory()->create();

    $draft = stagedMinionFor($user);

    $idle = imagecreatefromstring(Storage::disk('public')->get("custom-minions/{$user->id}/staged/{$draft->version_hash}/idle.png"));
    $measured = app(MeasureStripBodyHeight::class)->execute($idle);
    expect($draft->body_px)->toBe($measured)
        ->and($measured)->toBeGreaterThan(0)
        ->and($draft->slot)->toBeNull()
        ->and($draft->source)->toBe(CustomMinionSource::Upload)
        ->and($draft->animations)->toHaveKeys(['idle', 'walk', 'attack', 'death'])
        ->and($user->fresh()->customMinionDraft->version_hash)->toBe($draft->version_hash)
        ->and(CustomMinion::count())->toBe(0)
        ->and(poolRev($user))->toBe(0);
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

test('a sheet whose idle pose is empty is refused with nothing kept', function () {
    $user = User::factory()->create();
    $measure = Mockery::mock(MeasureStripBodyHeight::class);
    $measure->shouldReceive('execute')->andThrow(new BlankStripException);
    app()->instance(MeasureStripBodyHeight::class, $measure);

    expect(fn () => stagedMinionFor($user))->toThrow(BlankStripException::class, 'The idle pose is empty. Check the sheet and build again.');

    expect(CustomMinionDraft::count())->toBe(0);
    expect(Storage::disk('public')->allFiles("custom-minions/{$user->id}"))->toBe([]);
});

test('a new build replaces the earlier minion draft and its files', function () {
    $user = User::factory()->create();
    $first = stagedMinionFor($user, 0);

    $second = stagedMinionFor($user, 1);

    expect(CustomMinionDraft::count())->toBe(1)
        ->and($user->fresh()->customMinionDraft->version_hash)->toBe($second->version_hash);
    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/staged/{$first->version_hash}/idle.png");
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/staged/{$second->version_hash}/idle.png");
});

test('a draft built for a slot remembers it, and a plain rebuild forgets it', function () {
    $user = User::factory()->create();
    confirmedMinionFor($user, 0);

    $aimed = stagedMinionFor($user, 1, slot: 1);
    $plain = stagedMinionFor($user, 2);

    expect($aimed->slot)->toBe(1)
        ->and($plain->slot)->toBeNull()
        ->and(CustomMinionDraft::count())->toBe(1);
});

test('staging for a slot the player does not hold is refused', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    confirmedMinionFor($other, 0);

    expect(fn () => stagedMinionFor($user, 1, slot: 1))->toThrow(CustomMinionMissingException::class);

    expect(CustomMinionDraft::count())->toBe(0);
    expect(Storage::disk('public')->allFiles("custom-minions/{$user->id}"))->toBe([]);
});

test('adds the previewed minion into the lowest free slot, named after it and ticked', function () {
    $user = User::factory()->create();
    CustomMinion::factory()->create(['user_id' => $user->id, 'slot' => 1]);
    CustomMinion::factory()->create(['user_id' => $user->id, 'slot' => 3]);
    $draft = stagedMinionFor($user);

    $confirmed = app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash);

    $minion = $confirmed->minion;
    expect($confirmed->outcome)->toBe(CustomMinionOutcome::Added)
        ->and($minion->slot)->toBe(2)
        ->and($minion->name)->toBe('Minion 2')
        ->and(slotsLeftOut($user))->toBe([])
        ->and($minion->version_hash)->toBe($draft->version_hash)
        ->and($minion->source)->toBe(CustomMinionSource::Upload)
        ->and($minion->body_px)->toBe($draft->body_px)
        ->and($minion->animations)->toBe($draft->animations)
        ->and($user->fresh()->customMinions)->toHaveCount(3)
        ->and($user->fresh()->customMinionDraft)->toBeNull()
        ->and(poolRev($user))->toBe(1);
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$draft->version_hash}/idle.png");
    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/staged/{$draft->version_hash}/idle.png");
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 1);
    Event::assertDispatched(FighterMinionsChanged::class, fn (FighterMinionsChanged $event) => $event->user->is($user) && $event->user->minionPoolState()['rev'] === 1);
});

test('replacing keeps the slot, the name and the tick, and changes the look', function () {
    $user = User::factory()->create();
    $old = confirmedMinionFor($user, 0);
    $old->name = 'Biter';
    $old->save();
    leaveSlotsOut($user, [$old->slot]);
    $draft = stagedMinionFor($user, 1, slot: $old->slot);

    $confirmed = app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash);

    $minion = $confirmed->minion;
    expect($confirmed->outcome)->toBe(CustomMinionOutcome::Replaced)
        ->and($minion->slot)->toBe($old->slot)
        ->and($minion->name)->toBe('Biter')
        ->and(slotsLeftOut($user))->toBe([$old->slot])
        ->and($minion->version_hash)->toBe($draft->version_hash)
        ->and($old->version_hash)->not->toBe($draft->version_hash)
        ->and(CustomMinion::count())->toBe(1)
        ->and($user->fresh()->customMinionDraft)->toBeNull()
        ->and(poolRev($user))->toBe(2);
    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/{$old->version_hash}/idle.png");
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$draft->version_hash}/idle.png");
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 2);
});

test('replacing a ticked minion leaves it ticked, and the new look is in the pool', function () {
    $user = User::factory()->create();
    $old = confirmedMinionFor($user, 0);
    $draft = stagedMinionFor($user, 1, slot: $old->slot);

    app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash);

    expect(slotsLeftOut($user))->toBe([])
        ->and($user->fresh()->minionPoolState()['custom'])->toHaveCount(1)
        ->and($user->fresh()->minionPoolState()['custom'][0]['version'])->toBe($draft->version_hash);
});

test('a minion added into the slot a removed, unticked minion left is ticked', function () {
    $user = User::factory()->create();
    $gone = confirmedMinionFor($user, 0);
    leaveSlotsOut($user, [$gone->slot]);
    app(RemoveCustomMinion::class)->execute($gone);

    $added = app(ConfirmCustomMinion::class)->execute($user, stagedMinionFor($user, 1)->version_hash)->minion;

    expect($added->slot)->toBe($gone->slot)
        ->and(slotsLeftOut($user))->toBe([])
        ->and($user->fresh()->minionPoolState()['custom'])->toHaveCount(1);
});

test('a minion added into a slot the pool still leaves out is ticked all the same', function () {
    $user = User::factory()->create();
    leaveSlotsOut($user, [1]);

    $added = app(ConfirmCustomMinion::class)->execute($user, stagedMinionFor($user)->version_hash)->minion;

    expect($added->slot)->toBe(1)
        ->and(slotsLeftOut($user))->toBe([]);
});

test('a draft aimed at a slot whose minion was removed meanwhile takes that slot as a new minion', function () {
    $user = User::factory()->create();
    confirmedMinionFor($user, 0);
    $gone = CustomMinion::factory()->create(['user_id' => $user->id, 'slot' => 2, 'name' => 'Gone']);
    $draft = stagedMinionFor($user, 1, slot: 2);
    app(RemoveCustomMinion::class)->execute($gone);

    $confirmed = app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash);

    expect($confirmed->outcome)->toBe(CustomMinionOutcome::Added)
        ->and($confirmed->minion->slot)->toBe(2)
        ->and($confirmed->minion->name)->toBe('Minion 2')
        ->and($user->fresh()->customMinions->pluck('slot')->all())->toBe([1, 2]);
});

test('treats confirming an added minion again as already in the set', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);
    $confirm = app(ConfirmCustomMinion::class);
    $first = $confirm->execute($user, $draft->version_hash);

    $again = $confirm->execute($user, $draft->version_hash);

    expect($again->outcome)->toBe(CustomMinionOutcome::AlreadyInSet)
        ->and($again->minion->id)->toBe($first->minion->id)
        ->and(CustomMinion::count())->toBe(1)
        ->and(poolRev($user))->toBe(1);
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$draft->version_hash}/idle.png");
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 1);
});

test('confirming a hash already in the set drops the draft of that hash and its staged files', function () {
    $user = User::factory()->create();
    $present = confirmedMinionFor($user, 0);
    CustomMinionDraft::factory()->create(['user_id' => $user->id, 'version_hash' => $present->version_hash]);
    Storage::disk('public')->put("custom-minions/{$user->id}/staged/{$present->version_hash}/idle.png", 'staged');

    $confirmed = app(ConfirmCustomMinion::class)->execute($user, $present->version_hash);

    expect($confirmed->outcome)->toBe(CustomMinionOutcome::AlreadyInSet)
        ->and($user->fresh()->customMinionDraft)->toBeNull()
        ->and(poolRev($user))->toBe(1);
    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/staged/{$present->version_hash}/idle.png");
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$present->version_hash}/idle.png");
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 1);
});

test('confirming a hash already in the set leaves a different draft alone', function () {
    $user = User::factory()->create();
    $present = confirmedMinionFor($user, 0);
    $other = stagedMinionFor($user, 1);

    $confirmed = app(ConfirmCustomMinion::class)->execute($user, $present->version_hash);

    expect($confirmed->outcome)->toBe(CustomMinionOutcome::AlreadyInSet)
        ->and($user->fresh()->customMinionDraft->version_hash)->toBe($other->version_hash);
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/staged/{$other->version_hash}/idle.png");
});

test('refuses a stale preview', function () {
    $user = User::factory()->create();
    $stale = stagedMinionFor($user, 0);
    $current = stagedMinionFor($user, 1);

    expect(fn () => app(ConfirmCustomMinion::class)->execute($user, $stale->version_hash))
        ->toThrow(CustomMinionDraftMissingException::class, 'This preview was replaced. Build it again.');

    expect(CustomMinion::count())->toBe(0)
        ->and($user->fresh()->customMinionDraft->version_hash)->toBe($current->version_hash);
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

test('refuses a preview that is another player\'s', function () {
    $owner = User::factory()->create();
    $thief = User::factory()->create();
    $draft = stagedMinionFor($owner);

    expect(fn () => app(ConfirmCustomMinion::class)->execute($thief, $draft->version_hash))
        ->toThrow(CustomMinionDraftMissingException::class);

    expect(CustomMinion::count())->toBe(0)
        ->and($owner->fresh()->customMinionDraft->version_hash)->toBe($draft->version_hash)
        ->and(poolRev($thief))->toBe(0);
    Storage::disk('public')->assertExists("custom-minions/{$owner->id}/staged/{$draft->version_hash}/idle.png");
});

test('a full set refuses a new minion and keeps the draft', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);
    fillMinionSet($user);

    expect(fn () => app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash))
        ->toThrow(CustomMinionSetFullException::class);

    expect(CustomMinion::count())->toBe(3)
        ->and($user->fresh()->customMinionDraft->version_hash)->toBe($draft->version_hash)
        ->and(poolRev($user))->toBe(0);
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/staged/{$draft->version_hash}/idle.png");
    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/{$draft->version_hash}/idle.png");
    expect(fn () => stagedMinionFor($user, 1))->toThrow(CustomMinionSetFullException::class);
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

test('a full set can still replace a minion', function () {
    $user = User::factory()->create();
    fillMinionSet($user);

    $draft = stagedMinionFor($user, 1, slot: 2);
    $confirmed = app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash);

    expect($confirmed->outcome)->toBe(CustomMinionOutcome::Replaced)
        ->and($confirmed->minion->slot)->toBe(2)
        ->and(CustomMinion::count())->toBe(3);
});

test('refuses building the same image twice', function () {
    $user = User::factory()->create();
    $added = confirmedMinionFor($user);

    expect(fn () => stagedMinionFor($user))
        ->toThrow(CustomMinionDuplicateException::class, 'Already in your minions.');

    expect(CustomMinion::count())->toBe(1)
        ->and($user->fresh()->customMinionDraft)->toBeNull();
    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/staged/{$added->version_hash}/idle.png");
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$added->version_hash}/idle.png");
});

test('keeps the earlier draft and its files when a duplicate build is refused', function () {
    $user = User::factory()->create();
    $added = confirmedMinionFor($user, 0);
    $draft = stagedMinionFor($user, 1);

    expect(fn () => stagedMinionFor($user, 0))->toThrow(CustomMinionDuplicateException::class);

    expect($user->fresh()->customMinionDraft->version_hash)->toBe($draft->version_hash);
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/staged/{$draft->version_hash}/idle.png");
    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/staged/{$added->version_hash}/idle.png");
});

test('retargeting turns a draft that adds into one that replaces', function () {
    $user = User::factory()->create();
    $old = confirmedMinionFor($user, 0);
    $draft = stagedMinionFor($user, 1);
    expect($draft->slot)->toBeNull();

    $retargeted = app(RetargetCustomMinionDraft::class)->execute($user, $draft->version_hash, $old->slot);
    $confirmed = app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash);

    expect($retargeted->slot)->toBe($old->slot)
        ->and($confirmed->outcome)->toBe(CustomMinionOutcome::Replaced)
        ->and($confirmed->minion->slot)->toBe($old->slot)
        ->and(CustomMinion::count())->toBe(1)
        ->and(poolRev($user))->toBe(2);
});

test('retargeting leaves the pool alone and refuses slots and drafts that are not the player\'s', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $theirs = confirmedMinionFor($other, 0);
    $draft = stagedMinionFor($user, 1);
    $retarget = app(RetargetCustomMinionDraft::class);

    expect(fn () => $retarget->execute($user, $draft->version_hash, $theirs->slot))->toThrow(CustomMinionMissingException::class)
        ->and(fn () => $retarget->execute($user, 'aaaaaaaaaaaa', 1))->toThrow(CustomMinionDraftMissingException::class)
        ->and(fn () => $retarget->execute($other, $draft->version_hash, $theirs->slot))->toThrow(CustomMinionDraftMissingException::class);

    expect($user->fresh()->customMinionDraft->slot)->toBeNull()
        ->and(poolRev($user))->toBe(0);
});

test('every change of the pool bumps the revision once, and a draft changes nothing', function () {
    $user = User::factory()->create();

    $draft = stagedMinionFor($user, 0);
    expect(poolRev($user))->toBe(0);

    $added = app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash)->minion;
    expect(poolRev($user))->toBe(1);

    $replacement = stagedMinionFor($user, 1, slot: $added->slot);
    app(RetargetCustomMinionDraft::class)->execute($user, $replacement->version_hash, $added->slot);
    app(DiscardCustomMinionDraft::class)->execute($user, $replacement->version_hash);
    expect(poolRev($user))->toBe(1);

    $replaced = app(ConfirmCustomMinion::class)->execute($user, stagedMinionFor($user, 1, slot: $added->slot)->version_hash)->minion;
    expect(poolRev($user))->toBe(2);

    app(RemoveCustomMinion::class)->execute($replaced);
    expect(poolRev($user))->toBe(3);
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 3);
});

test('announces the revision the database holds even when the player was loaded before it moved', function () {
    $user = User::factory()->create();
    $stale = User::find($user->id);
    confirmedMinionFor($user, 0);
    $draft = stagedMinionFor($stale, 1);

    app(ConfirmCustomMinion::class)->execute($stale, $draft->version_hash);

    expect(poolRev($user))->toBe(2);
    Event::assertDispatched(FighterMinionsChanged::class, fn (FighterMinionsChanged $event) => $event->user->is($user) && $event->user->minionPoolState()['rev'] === 2);
});

test('keeps the staged files and the draft when the insert fails, and a retry adds the minion', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);
    $failing = true;
    CustomMinion::creating(function () use (&$failing): void {
        if ($failing) {
            throw new RuntimeException('insert failed');
        }
    });

    expect(fn () => app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash))->toThrow(RuntimeException::class);

    expect(CustomMinion::count())->toBe(0)
        ->and($user->fresh()->customMinionDraft->version_hash)->toBe($draft->version_hash)
        ->and(poolRev($user))->toBe(0);
    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertExists("custom-minions/{$user->id}/staged/{$draft->version_hash}/{$animation}.png");
    }
    Event::assertNotDispatched(FighterMinionsChanged::class);

    $failing = false;
    $confirmed = app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash);

    expect($confirmed->outcome)->toBe(CustomMinionOutcome::Added)
        ->and($user->fresh()->customMinionDraft)->toBeNull();
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$draft->version_hash}/idle.png");
    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/staged/{$draft->version_hash}/idle.png");
});

test('keeps the minion being replaced when the insert of its replacement fails', function () {
    $user = User::factory()->create();
    $old = confirmedMinionFor($user, 0);
    $draft = stagedMinionFor($user, 1, slot: $old->slot);
    CustomMinion::creating(function (): void {
        throw new RuntimeException('insert failed');
    });

    expect(fn () => app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash))->toThrow(RuntimeException::class);

    expect(CustomMinion::pluck('version_hash')->all())->toBe([$old->version_hash])
        ->and(poolRev($user))->toBe(1);
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$old->version_hash}/idle.png");
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/staged/{$draft->version_hash}/idle.png");
});

test('keeps the staged files and the draft when the copy fails, and a retry adds the minion', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);
    $real = Storage::disk('public');
    breakPublicDisk('copy', fn () => true);

    expect(fn () => app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash))->toThrow(CustomCharacterStorageException::class);

    expect(CustomMinion::count())->toBe(0)
        ->and($user->fresh()->customMinionDraft->version_hash)->toBe($draft->version_hash);
    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        $real->assertExists("custom-minions/{$user->id}/staged/{$draft->version_hash}/{$animation}.png");
        $real->assertMissing("custom-minions/{$user->id}/{$draft->version_hash}/{$animation}.png");
    }

    Storage::set('public', $real);
    $confirmed = app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash);

    expect($confirmed->outcome)->toBe(CustomMinionOutcome::Added);
    $real->assertExists("custom-minions/{$user->id}/{$draft->version_hash}/idle.png");
});

test('a failed insert of the draft keeps the earlier draft and leaves no new folder', function () {
    $user = User::factory()->create();
    $earlier = stagedMinionFor($user, 0);
    CustomMinionDraft::saving(function (): void {
        throw new RuntimeException('insert failed');
    });

    expect(fn () => stagedMinionFor($user, 1))->toThrow(RuntimeException::class);

    expect($user->fresh()->customMinionDraft->version_hash)->toBe($earlier->version_hash);
    expect(Storage::disk('public')->directories("custom-minions/{$user->id}/staged"))->toBe(["custom-minions/{$user->id}/staged/{$earlier->version_hash}"]);
});

test('a failed insert of a first draft leaves no folder at all', function () {
    $user = User::factory()->create();
    CustomMinionDraft::saving(function (): void {
        throw new RuntimeException('insert failed');
    });

    expect(fn () => stagedMinionFor($user))->toThrow(RuntimeException::class);

    expect(CustomMinionDraft::count())->toBe(0);
    expect(Storage::disk('public')->allFiles("custom-minions/{$user->id}"))->toBe([]);
});

test('a failed write keeps the earlier draft and its files', function () {
    $user = User::factory()->create();
    $earlier = stagedMinionFor($user, 0);
    $real = Storage::disk('public');
    breakPublicDisk('put', fn () => true);

    expect(fn () => stagedMinionFor($user, 1))->toThrow(CustomCharacterStorageException::class);

    expect($user->fresh()->customMinionDraft->version_hash)->toBe($earlier->version_hash);
    expect($real->directories("custom-minions/{$user->id}/staged"))->toBe(["custom-minions/{$user->id}/staged/{$earlier->version_hash}"]);
});

test('stages the custom fighter as a minion draft without adding it', function () {
    $user = User::factory()->create();
    $fighter = fighterWithFiles($user);

    $draft = app(StageMinionFromFighter::class)->execute($user);

    $idle = imagecreatefromstring(Storage::disk('public')->get("custom-characters/{$user->id}/{$fighter->version_hash}/idle.png"));
    expect($draft->source)->toBe(CustomMinionSource::Fighter)
        ->and($draft->version_hash)->toBe($fighter->version_hash)
        ->and($draft->name)->toBe($fighter->name)
        ->and($draft->slot)->toBeNull()
        ->and($draft->animations)->toBe($fighter->animations)
        ->and($draft->body_px)->toBe(app(MeasureStripBodyHeight::class)->execute($idle))
        ->and(CustomMinion::count())->toBe(0)
        ->and(poolRev($user))->toBe(0);
    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertExists("custom-minions/{$user->id}/staged/{$fighter->version_hash}/{$animation}.png");
        Storage::disk('public')->assertExists("custom-characters/{$user->id}/{$fighter->version_hash}/{$animation}.png");
    }
    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/{$fighter->version_hash}/idle.png");
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

test('a minion copied from the fighter joins the set under the fighter\'s name, and keeps its files when the fighter goes', function () {
    $user = User::factory()->create();
    $fighter = fighterWithFiles($user);
    $draft = app(StageMinionFromFighter::class)->execute($user);

    $minion = app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash)->minion;
    app(RemoveCustomCharacter::class)->execute($fighter);

    expect($minion->source)->toBe(CustomMinionSource::Fighter)
        ->and($minion->name)->toBe($fighter->name)
        ->and(CustomMinion::find($minion->id))->not->toBeNull();
    Storage::disk('public')->assertMissing("custom-characters/{$user->id}/{$fighter->version_hash}/idle.png");
    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$minion->version_hash}/{$animation}.png");
    }
});

test('keeps minion files when the fighter confirms a new version', function () {
    $user = User::factory()->create();
    $confirmFighter = app(ConfirmCustomCharacter::class);
    $confirmFighter->execute($user, stagedFor($user, 0)->version_hash);
    $draft = app(StageMinionFromFighter::class)->execute($user);
    $minion = app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash)->minion;

    $confirmFighter->execute($user, stagedFor($user, 1)->version_hash);

    expect(CustomMinion::find($minion->id))->not->toBeNull();
    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$minion->version_hash}/{$animation}.png");
    }
});

test('a fighter copy replacing a minion keeps that minion\'s name', function () {
    $user = User::factory()->create();
    $old = confirmedMinionFor($user, 1);
    $old->name = 'Biter';
    $old->save();
    fighterWithFiles($user);

    $draft = app(StageMinionFromFighter::class)->execute($user, slot: $old->slot);
    $confirmed = app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash);

    expect($draft->slot)->toBe($old->slot)
        ->and($confirmed->outcome)->toBe(CustomMinionOutcome::Replaced)
        ->and($confirmed->minion->name)->toBe('Biter')
        ->and($confirmed->minion->source)->toBe(CustomMinionSource::Fighter);
});

test('has nothing to stage when the player has no custom fighter', function () {
    $user = User::factory()->create();

    expect(fn () => app(StageMinionFromFighter::class)->execute($user))
        ->toThrow(CustomMinionNoFighterException::class, 'You have no custom fighter yet. Make one in the Fighter tab.');

    expect(CustomMinionDraft::count())->toBe(0);
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

test('refuses copying the fighter when it is already in the set', function () {
    $user = User::factory()->create();
    fighterWithFiles($user);
    confirmedMinionFor($user, 0);

    expect(fn () => app(StageMinionFromFighter::class)->execute($user))->toThrow(CustomMinionDuplicateException::class);

    expect(CustomMinionDraft::count())->toBe(0);
});

test('refuses copying the fighter when the set is full, unless it replaces', function () {
    $user = User::factory()->create();
    fighterWithFiles($user);
    fillMinionSet($user);

    expect(fn () => app(StageMinionFromFighter::class)->execute($user))->toThrow(CustomMinionSetFullException::class)
        ->and(fn () => app(StageMinionFromFighter::class)->execute($user, slot: 9))->toThrow(CustomMinionMissingException::class);
    $draft = app(StageMinionFromFighter::class)->execute($user, slot: 3);

    expect($draft->slot)->toBe(3);
});

test('an unreadable fighter strip leaves no folder behind', function () {
    $user = User::factory()->create();
    $fighter = fighterWithFiles($user);
    $real = Storage::disk('public');
    breakPublicDisk('get', fn (string $path) => str_ends_with($path, "custom-characters/{$user->id}/{$fighter->version_hash}/idle.png"));

    expect(fn () => app(StageMinionFromFighter::class)->execute($user))->toThrow(CustomCharacterStorageException::class);

    expect(CustomMinionDraft::count())->toBe(0);
    expect($real->allFiles("custom-minions/{$user->id}"))->toBe([]);
});

test('a failed copy of the fighter leaves no folder behind', function () {
    $user = User::factory()->create();
    fighterWithFiles($user);
    $real = Storage::disk('public');
    breakPublicDisk('copy', fn (string $from) => str_ends_with($from, 'walk.png'));

    expect(fn () => app(StageMinionFromFighter::class)->execute($user))->toThrow(CustomCharacterStorageException::class);

    expect(CustomMinionDraft::count())->toBe(0);
    expect($real->allFiles("custom-minions/{$user->id}"))->toBe([]);
});

test('a failed insert of the fighter copy keeps the earlier draft and leaves no new folder', function () {
    $user = User::factory()->create();
    $fighter = fighterWithFiles($user);
    $earlier = stagedMinionFor($user, 1);
    CustomMinionDraft::saving(function (): void {
        throw new RuntimeException('insert failed');
    });

    expect(fn () => app(StageMinionFromFighter::class)->execute($user))->toThrow(RuntimeException::class);

    expect($user->fresh()->customMinionDraft->version_hash)->toBe($earlier->version_hash);
    expect(Storage::disk('public')->directories("custom-minions/{$user->id}/staged"))->toBe(["custom-minions/{$user->id}/staged/{$earlier->version_hash}"]);
    Storage::disk('public')->assertExists("custom-characters/{$user->id}/{$fighter->version_hash}/idle.png");
});

test('copying the fighter waits for the player lock', function () {
    $user = User::factory()->create();
    fighterWithFiles($user);
    $held = holdMinionLock($user);

    expect(fn () => app(StageMinionFromFighter::class)->execute($user))->toThrow(CustomCharacterBusyException::class);

    expect(CustomMinionDraft::count())->toBe(0);
    $held->release();
    expect(app(StageMinionFromFighter::class)->execute($user)->version_hash)->toBeString();
});

test('staging an upload waits for the player lock', function () {
    $user = User::factory()->create();
    $held = holdMinionLock($user);

    expect(fn () => stagedMinionFor($user))->toThrow(CustomCharacterBusyException::class);

    $held->release();
    expect(app(StageCustomMinion::class)->execute($user, pngBytes(variantPoseSheet(0)))->version_hash)->toBeString();
});

test('removes a minion and its files, and bumps the revision', function () {
    $user = User::factory()->create();
    $removed = confirmedMinionFor($user, 0);
    $kept = confirmedMinionFor($user, 1);

    $result = app(RemoveCustomMinion::class)->execute($removed);

    expect($result)->toBeTrue()
        ->and(CustomMinion::pluck('id')->all())->toBe([$kept->id])
        ->and(poolRev($user))->toBe(3);
    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/{$removed->version_hash}/idle.png");
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$kept->version_hash}/idle.png");
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 3);
    Event::assertDispatched(FighterMinionsChanged::class, fn (FighterMinionsChanged $event) => $event->user->is($user) && $event->user->minionPoolState()['rev'] === 3);
});

test('removing a minion that is already gone is a no-op', function () {
    $user = User::factory()->create();
    $minion = confirmedMinionFor($user);
    $stale = CustomMinion::find($minion->id);
    expect(app(RemoveCustomMinion::class)->execute($minion))->toBeTrue();

    expect(app(RemoveCustomMinion::class)->execute($stale))->toBeFalse();

    expect(CustomMinion::count())->toBe(0)
        ->and(poolRev($user))->toBe(2);
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 2);
});

test('removing a minion takes its slot out of the pool\'s unticked slots, in the same revision step', function () {
    $user = User::factory()->create();
    $minion = confirmedMinionFor($user);
    leaveSlotsOut($user, [$minion->slot]);

    app(RemoveCustomMinion::class)->execute($minion);

    expect(slotsLeftOut($user))->toBe([])
        ->and(poolRev($user))->toBe(2);
});

test('removing a minion through a stale owner model announces the revision the database holds', function () {
    $user = User::factory()->create();
    $minion = confirmedMinionFor($user);
    $minion->setRelation('user', User::find($user->id));
    leaveSlotsOut($user, [$minion->slot]);

    app(RemoveCustomMinion::class)->execute($minion);

    expect(poolRev($user))->toBe(2);
    Event::assertDispatched(FighterMinionsChanged::class, fn (FighterMinionsChanged $event) => $event->user->minionPoolState()['rev'] === 2);
});

test('a failed delete keeps the minion, its files and the revision', function () {
    $user = User::factory()->create();
    $minion = confirmedMinionFor($user);
    CustomMinion::deleting(function (): void {
        throw new RuntimeException('delete failed');
    });

    expect(fn () => app(RemoveCustomMinion::class)->execute($minion))->toThrow(RuntimeException::class);

    expect(CustomMinion::count())->toBe(1)
        ->and(poolRev($user))->toBe(1);
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$minion->version_hash}/idle.png");
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 1);
});

test('a disk that will not delete does not stop the removal', function () {
    $user = User::factory()->create();
    $minion = confirmedMinionFor($user);
    breakPublicDisk('deleteDirectory', fn () => true);

    app(RemoveCustomMinion::class)->execute($minion);

    expect(CustomMinion::count())->toBe(0)
        ->and(poolRev($user))->toBe(2);
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 2);
});

test('tells a second tab the player is busy', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);
    $held = holdMinionLock($user);

    expect(fn () => app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash))
        ->toThrow(CustomCharacterBusyException::class);

    expect(CustomMinion::count())->toBe(0);
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/staged/{$draft->version_hash}/idle.png");
    $held->release();
    app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash);
    expect(CustomMinion::count())->toBe(1);
});

test('discards a minion draft', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);

    app(DiscardCustomMinionDraft::class)->execute($user, $draft->version_hash);

    expect($user->fresh()->customMinionDraft)->toBeNull()
        ->and(poolRev($user))->toBe(0);
    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/staged/{$draft->version_hash}/idle.png");
});

test('a stale discard of a replaced preview keeps the player\'s newer draft and its files', function () {
    $user = User::factory()->create();
    $seen = stagedMinionFor($user);
    $newer = stagedMinionFor($user, 1);

    expect(fn () => app(DiscardCustomMinionDraft::class)->execute($user, $seen->version_hash))
        ->toThrow(CustomMinionDraftMissingException::class);

    expect($user->fresh()->customMinionDraft->version_hash)->toBe($newer->version_hash);
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/staged/{$newer->version_hash}/idle.png");
});

test('discarding when the player has no draft any more changes nothing', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);
    app(DiscardCustomMinionDraft::class)->execute($user, $draft->version_hash);

    expect(fn () => app(DiscardCustomMinionDraft::class)->execute($user, $draft->version_hash))
        ->toThrow(CustomMinionDraftMissingException::class);
});

test('building the same sheet again refreshes the draft so a prune reading the old time leaves it', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);
    CustomMinionDraft::whereKey($draft->id)->update(['updated_at' => now()->subHours(48)]);

    $again = stagedMinionFor($user);

    expect($again->version_hash)->toBe($draft->version_hash)
        ->and($again->fresh()->updated_at->gt(now()->subHour()))->toBeTrue();
});

test('a discard bound to a cutoff leaves a draft touched since', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);

    expect(fn () => app(DiscardCustomMinionDraft::class)->execute($user, $draft->version_hash, now()->subHours(24)))
        ->toThrow(CustomMinionDraftMissingException::class);

    expect($user->fresh()->customMinionDraft)->not->toBeNull();
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/staged/{$draft->version_hash}/idle.png");
});

test('confirming with the slot the preview was shown for refuses a preview that was re-aimed since', function () {
    $user = User::factory()->create();
    $kept = confirmedMinionFor($user, 0);
    $second = confirmedMinionFor($user, 1);
    $draft = stagedMinionFor($user, 2, slot: $kept->slot);
    app(RetargetCustomMinionDraft::class)->execute($user, $draft->version_hash, $second->slot);

    expect(fn () => app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash, $kept->slot))
        ->toThrow(CustomMinionDraftMissingException::class);

    expect(CustomMinion::count())->toBe(2)
        ->and(CustomMinion::where('slot', $second->slot)->value('version_hash'))->toBe($second->version_hash)
        ->and($user->fresh()->customMinionDraft->slot)->toBe($second->slot)
        ->and(poolRev($user))->toBe(2);

    $confirmed = app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash, $second->slot);
    expect($confirmed->outcome)->toBe(CustomMinionOutcome::Replaced)->and($confirmed->minion->slot)->toBe($second->slot);
});
