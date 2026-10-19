<?php

use App\Enums\CustomMinionSource;
use App\Events\FighterCharacterChanged;
use App\Events\FighterMinionsChanged;
use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterVersionException;
use App\Exceptions\CustomMinionDraftMissingException;
use App\Exceptions\CustomMinionDuplicateException;
use App\Exceptions\CustomMinionSetFullException;
use App\Models\CustomMinion;
use App\Models\CustomMinionDraft;
use App\Models\User;
use App\Services\CustomCharacter\AddFighterAsMinion;
use App\Services\CustomCharacter\ConfirmCustomCharacter;
use App\Services\CustomCharacter\ConfirmCustomMinion;
use App\Services\CustomCharacter\DiscardCustomMinionDraft;
use App\Services\CustomCharacter\MeasureStripBodyHeight;
use App\Services\CustomCharacter\RemoveCustomCharacter;
use App\Services\CustomCharacter\RemoveCustomMinion;
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

test('builds a minion draft with its measured body height', function () {
    $user = User::factory()->create();

    $draft = stagedMinionFor($user);

    $idle = imagecreatefromstring(Storage::disk('public')->get("custom-minions/{$user->id}/staged/{$draft->version_hash}/idle.png"));
    $measured = app(MeasureStripBodyHeight::class)->execute($idle);
    expect($draft->body_px)->toBe($measured)
        ->and($measured)->toBeGreaterThan(0)
        ->and($draft->animations)->toHaveKeys(['idle', 'walk', 'attack', 'death'])
        ->and($user->fresh()->customMinionDraft->version_hash)->toBe($draft->version_hash)
        ->and(CustomMinion::count())->toBe(0);
    Event::assertNotDispatched(FighterMinionsChanged::class);
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

test('adds the previewed minion and announces the set', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);

    $minion = app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash);

    expect($minion->version_hash)->toBe($draft->version_hash)
        ->and($minion->source)->toBe(CustomMinionSource::Upload)
        ->and($minion->body_px)->toBe($draft->body_px)
        ->and($minion->animations)->toBe($draft->animations)
        ->and($user->fresh()->customMinions)->toHaveCount(1)
        ->and($user->fresh()->customMinionDraft)->toBeNull();
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$draft->version_hash}/idle.png");
    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/staged/{$draft->version_hash}/idle.png");
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 1);
    Event::assertDispatched(FighterMinionsChanged::class, fn (FighterMinionsChanged $event) => $event->user->is($user)
        && $event->user->customMinionsState() === [$minion->broadcastState()]);
});

test('treats confirming an added minion again as a no-op', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);
    $confirm = app(ConfirmCustomMinion::class);
    $first = $confirm->execute($user, $draft->version_hash);

    $again = $confirm->execute($user, $draft->version_hash);

    expect($again->id)->toBe($first->id)
        ->and(CustomMinion::count())->toBe(1);
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$draft->version_hash}/idle.png");
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 1);
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

test('refuses a fourth minion and keeps the draft', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);
    CustomMinion::factory()->count(3)->create(['user_id' => $user->id]);

    expect(fn () => app(ConfirmCustomMinion::class)->execute($user, $draft->version_hash))
        ->toThrow(CustomMinionSetFullException::class, '3 of 3. Remove one to add another.');

    expect(CustomMinion::count())->toBe(3)
        ->and($user->fresh()->customMinionDraft->version_hash)->toBe($draft->version_hash);
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/staged/{$draft->version_hash}/idle.png");
    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/{$draft->version_hash}/idle.png");
    expect(fn () => stagedMinionFor($user, 1))->toThrow(CustomMinionSetFullException::class);
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

test('refuses building the same image twice', function () {
    $user = User::factory()->create();
    $added = app(ConfirmCustomMinion::class)->execute($user, stagedMinionFor($user)->version_hash);

    expect(fn () => stagedMinionFor($user))
        ->toThrow(CustomMinionDuplicateException::class, 'Already in your minions.');

    expect(CustomMinion::count())->toBe(1)
        ->and($user->fresh()->customMinionDraft)->toBeNull();
    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/staged/{$added->version_hash}/idle.png");
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$added->version_hash}/idle.png");
});

test('keeps the earlier draft and its files when a duplicate build is refused', function () {
    $user = User::factory()->create();
    $added = app(ConfirmCustomMinion::class)->execute($user, stagedMinionFor($user, 0)->version_hash);
    $draft = stagedMinionFor($user, 1);

    expect(fn () => stagedMinionFor($user, 0))->toThrow(CustomMinionDuplicateException::class);

    expect($user->fresh()->customMinionDraft->version_hash)->toBe($draft->version_hash);
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/staged/{$draft->version_hash}/idle.png");
    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/staged/{$added->version_hash}/idle.png");
});

test('adds the custom fighter as a minion', function () {
    $user = User::factory()->create();
    $fighter = app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user)->version_hash);

    $minion = app(AddFighterAsMinion::class)->execute($user);

    expect($minion->source)->toBe(CustomMinionSource::Fighter)
        ->and($minion->version_hash)->toBe($fighter->version_hash)
        ->and($minion->animations)->toBe($fighter->animations)
        ->and($minion->body_px)->toBeGreaterThan(0)
        ->and($user->fresh()->customMinions)->toHaveCount(1);
    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$fighter->version_hash}/{$animation}.png");
        Storage::disk('public')->assertExists("custom-characters/{$user->id}/{$fighter->version_hash}/{$animation}.png");
    }
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 1);
});

test('has nothing to add when the player has no custom fighter', function () {
    $user = User::factory()->create();

    expect(fn () => app(AddFighterAsMinion::class)->execute($user))
        ->toThrow(CustomCharacterVersionException::class);

    expect(CustomMinion::count())->toBe(0);
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

test('refuses adding the fighter twice', function () {
    $user = User::factory()->create();
    app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user)->version_hash);
    app(AddFighterAsMinion::class)->execute($user);

    expect(fn () => app(AddFighterAsMinion::class)->execute($user))
        ->toThrow(CustomMinionDuplicateException::class);

    expect(CustomMinion::count())->toBe(1);
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 1);
});

test('refuses adding the fighter when the set is full', function () {
    $user = User::factory()->create();
    app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user)->version_hash);
    CustomMinion::factory()->count(3)->create(['user_id' => $user->id]);

    expect(fn () => app(AddFighterAsMinion::class)->execute($user))
        ->toThrow(CustomMinionSetFullException::class);

    expect(CustomMinion::count())->toBe(3);
});

test('keeps a minion copied from the fighter when the fighter is removed', function () {
    $user = User::factory()->create();
    $fighter = app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user)->version_hash);
    $minion = app(AddFighterAsMinion::class)->execute($user);

    app(RemoveCustomCharacter::class)->execute($fighter);

    expect(CustomMinion::find($minion->id))->not->toBeNull();
    Storage::disk('public')->assertMissing("custom-characters/{$user->id}/{$fighter->version_hash}/idle.png");
    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$minion->version_hash}/{$animation}.png");
    }
});

test('keeps minion files when the fighter confirms a new version', function () {
    $user = User::factory()->create();
    $confirmFighter = app(ConfirmCustomCharacter::class);
    $confirmFighter->execute($user, stagedFor($user, 0)->version_hash);
    $minion = app(AddFighterAsMinion::class)->execute($user);

    $confirmFighter->execute($user, stagedFor($user, 1)->version_hash);

    expect(CustomMinion::find($minion->id))->not->toBeNull();
    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$minion->version_hash}/{$animation}.png");
    }
});

test('removes a minion and its files', function () {
    $user = User::factory()->create();
    $confirm = app(ConfirmCustomMinion::class);
    $removed = $confirm->execute($user, stagedMinionFor($user, 0)->version_hash);
    $kept = $confirm->execute($user, stagedMinionFor($user, 1)->version_hash);

    app(RemoveCustomMinion::class)->execute($removed);

    expect(CustomMinion::pluck('id')->all())->toBe([$kept->id]);
    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/{$removed->version_hash}/idle.png");
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/{$kept->version_hash}/idle.png");
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 3);
    Event::assertDispatched(FighterMinionsChanged::class, fn (FighterMinionsChanged $event) => $event->user->customMinionsState() === [$kept->broadcastState()]);
});

test('removing a minion that is already gone is a no-op', function () {
    $user = User::factory()->create();
    $minion = app(ConfirmCustomMinion::class)->execute($user, stagedMinionFor($user)->version_hash);
    $stale = CustomMinion::find($minion->id);
    app(RemoveCustomMinion::class)->execute($minion);

    app(RemoveCustomMinion::class)->execute($stale);

    expect(CustomMinion::count())->toBe(0);
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

    expect($user->fresh()->customMinionDraft)->toBeNull();
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
