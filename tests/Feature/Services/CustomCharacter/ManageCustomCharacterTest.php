<?php

use App\Events\FighterCharacterChanged;
use App\Exceptions\CustomCharacterVersionException;
use App\Models\CustomCharacter;
use App\Models\CustomCharacterVersion;
use App\Models\User;
use App\Services\CustomCharacter\ConfirmCustomCharacter;
use App\Services\CustomCharacter\DeleteCustomCharacterVersion;
use App\Services\CustomCharacter\EquipCustomCharacterVersion;
use App\Services\CustomCharacter\RemoveCustomCharacter;
use App\Services\CustomCharacter\RenameCustomCharacter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    Event::fake([FighterCharacterChanged::class]);
});

/**
 * A user with two confirmed versions, the second current.
 *
 * @return array{0: User, 1: string, 2: string} the user, the first (older) hash and the current hash
 */
function userWithTwoVersions(): array
{
    $user = User::factory()->create();
    $confirm = app(ConfirmCustomCharacter::class);
    $first = $confirm->execute($user, stagedFor($user, 0)->version_hash)->version_hash;
    $second = $confirm->execute($user, stagedFor($user, 1)->version_hash)->version_hash;
    Event::fake([FighterCharacterChanged::class]);

    return [$user->fresh(), $first, $second];
}

test('renames the fighter, trimming spaces', function () {
    $character = CustomCharacter::factory()->create();

    $renamed = app(RenameCustomCharacter::class)->execute($character->user, '  Sir Pixel  ');

    expect($renamed->name)->toBe('Sir Pixel')
        ->and($character->fresh()->name)->toBe('Sir Pixel');
});

test('renaming announces the new name to other viewers', function () {
    $character = CustomCharacter::factory()->create();

    app(RenameCustomCharacter::class)->execute($character->user, 'Sir Pixel');

    Event::assertDispatched(FighterCharacterChanged::class, fn (FighterCharacterChanged $event) => $event->user->is($character->user));
});

test('an empty name goes back to the default, and a long one is cut to the limit', function () {
    $character = CustomCharacter::factory()->create(['name' => 'Old']);
    $rename = app(RenameCustomCharacter::class);

    expect($rename->execute($character->user, '   ')->name)->toBe('Custom')
        ->and(mb_strlen($rename->execute($character->user, str_repeat('é', 80))->name))->toBe(24);
});

test('renaming is for someone who has a fighter', function () {
    expect(fn () => app(RenameCustomCharacter::class)->execute(User::factory()->create(), 'Nobody'))
        ->toThrow(CustomCharacterVersionException::class);
});

test('equipping an earlier version makes it current and equipped, and tells the battlefield', function () {
    [$user, $first, $second] = userWithTwoVersions();
    $user->customCharacter->update(['active' => false]);

    $character = app(EquipCustomCharacterVersion::class)->execute($user, $first);

    expect($character->version_hash)->toBe($first)
        ->and($character->active)->toBeTrue()
        ->and($character->animations['walk']['frames'])->toBe(8)
        ->and($character->versions()->count())->toBe(2);
    Event::assertDispatched(FighterCharacterChanged::class, fn ($event) => $event->user->customFighterState()['version'] === $first);
});

test('a player cannot equip a version they do not have, nor someone else\'s', function () {
    [$user, , $second] = userWithTwoVersions();
    $other = CustomCharacter::factory()->create();

    expect(fn () => app(EquipCustomCharacterVersion::class)->execute($user, 'abcdefabcdef'))->toThrow(CustomCharacterVersionException::class)
        ->and(fn () => app(EquipCustomCharacterVersion::class)->execute($user, $other->version_hash))->toThrow(CustomCharacterVersionException::class);

    expect($user->fresh()->customCharacter->version_hash)->toBe($second);
});

test('deleting an earlier version removes its row and its files, and does not touch the battlefield', function () {
    [$user, $first, $second] = userWithTwoVersions();

    app(DeleteCustomCharacterVersion::class)->execute($user, $first);

    expect($user->customCharacter->versions()->pluck('version_hash')->all())->toBe([$second]);
    Storage::disk('public')->assertMissing("custom-characters/{$user->id}/{$first}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/{$user->id}/{$second}/idle.png");
    Event::assertNotDispatched(FighterCharacterChanged::class);
});

test('the version in use cannot be deleted, and neither can one the player does not have', function () {
    [$user, , $second] = userWithTwoVersions();

    expect(fn () => app(DeleteCustomCharacterVersion::class)->execute($user, $second))->toThrow(CustomCharacterVersionException::class)
        ->and(fn () => app(DeleteCustomCharacterVersion::class)->execute($user, 'abcdefabcdef'))->toThrow(CustomCharacterVersionException::class);

    Storage::disk('public')->assertExists("custom-characters/{$user->id}/{$second}/idle.png");
});

test('removing the fighter deletes the row, every version and every file, and tells the battlefield', function () {
    [$user, $first, $second] = userWithTwoVersions();
    $character = $user->customCharacter;

    app(RemoveCustomCharacter::class)->execute($character);

    expect(CustomCharacter::count())->toBe(0)
        ->and(CustomCharacterVersion::count())->toBe(0)
        ->and($user->fresh()->customCharacter)->toBeNull();
    Storage::disk('public')->assertMissing("custom-characters/{$user->id}/{$first}/idle.png");
    Storage::disk('public')->assertMissing("custom-characters/{$user->id}/{$second}/idle.png");
    Event::assertDispatched(FighterCharacterChanged::class, fn ($event) => $event->user->is($user) && $event->user->customFighterState() === null);
});

test('removing keeps the roster character the player had equipped, as the fallback', function () {
    $character = CustomCharacter::factory()->create();
    $character->user->forceFill(['equipped_character' => App\Enums\FighterCharacter::Werebear])->save();

    app(RemoveCustomCharacter::class)->execute($character);

    expect($character->user->fresh()->equipped_character)->toBe(App\Enums\FighterCharacter::Werebear)
        ->and($character->user->fresh()->characterForBoss(null))->toBe('werebear');
});

test('deleting a user leaves no files of theirs behind', function () {
    [$user, $first, $second] = userWithTwoVersions();

    $user->delete();

    Storage::disk('public')->assertMissing("custom-characters/{$user->id}/{$first}/idle.png");
    Storage::disk('public')->assertMissing("custom-characters/{$user->id}/{$second}/idle.png");
});

/**
 * Holds the player's lock, as a build running in another tab would.
 *
 * @param  User  $user  the player
 * @return Illuminate\Contracts\Cache\Lock the held lock; release it to let the player's changes through
 */
function holdPlayersLock(User $user): Illuminate\Contracts\Cache\Lock
{
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    $held = Cache::lock(App\Support\CacheKeys::customCharacterBuild($user->id), 30);
    $held->get();

    return $held;
}

test('removing the fighter also drops a pending draft and its files, so no preview is left broken', function () {
    [$user] = userWithTwoVersions();
    $draft = stagedFor($user, 2);

    app(RemoveCustomCharacter::class)->execute($user->customCharacter);

    expect(App\Models\CustomCharacterDraft::count())->toBe(0);
    Storage::disk('public')->assertMissing("custom-characters/{$user->id}/staged/{$draft->version_hash}/idle.png");
});

test('removing the fighter waits for the players other changes instead of racing them', function () {
    [$user] = userWithTwoVersions();
    $held = holdPlayersLock($user);

    expect(fn () => app(RemoveCustomCharacter::class)->execute($user->customCharacter))
        ->toThrow(App\Exceptions\CustomCharacterBusyException::class);

    expect(CustomCharacter::count())->toBe(1);
    $held->release();
    app(RemoveCustomCharacter::class)->execute($user->customCharacter);
    expect(CustomCharacter::count())->toBe(0);
});

test('discarding the draft waits for the players other changes instead of racing them', function () {
    $user = User::factory()->create();
    $draft = stagedFor($user);
    holdPlayersLock($user);

    expect(fn () => app(App\Services\CustomCharacter\DiscardCustomCharacterDraft::class)->execute($user))
        ->toThrow(App\Exceptions\CustomCharacterBusyException::class);

    expect($user->fresh()->customCharacterDraft->version_hash)->toBe($draft->version_hash);
    Storage::disk('public')->assertExists("custom-characters/{$user->id}/staged/{$draft->version_hash}/idle.png");
});

test('a versions files go while the lock is still held, so a re-confirm cannot slip in between', function () {
    [$user, $first] = userWithTwoVersions();
    $lockHeldWhenFilesGo = null;
    $this->partialMock(App\Services\CustomCharacter\CustomCharacterStore::class, function ($store) use ($user, &$lockHeldWhenFilesGo) {
        $store->shouldReceive('forgetVersion')->once()->andReturnUsing(function () use ($user, &$lockHeldWhenFilesGo): void {
            $probe = Cache::lock(App\Support\CacheKeys::customCharacterBuild($user->id), 5);
            $lockHeldWhenFilesGo = ! $probe->get();
            $probe->release();
        });
    });

    app(DeleteCustomCharacterVersion::class)->execute($user, $first);

    expect($lockHeldWhenFilesGo)->toBeTrue();
});

test('renaming waits for the players other changes, and strips control and bidi characters from the name', function () {
    $character = CustomCharacter::factory()->create();
    $rename = app(RenameCustomCharacter::class);

    expect($rename->execute($character->user, "Sir\u{202E} Pixel\u{0007}\u{200B}\n")->name)->toBe('Sir Pixel');

    holdPlayersLock($character->user);
    expect(fn () => $rename->execute($character->user, 'Late'))->toThrow(App\Exceptions\CustomCharacterBusyException::class);
});

test('equipping the custom fighter or a roster character goes through the lock and tells the battlefield', function () {
    [$user] = userWithTwoVersions();
    $user->customCharacter->update(['active' => false]);

    app(App\Services\CustomCharacter\EquipCustomCharacter::class)->execute($user);
    expect($user->customCharacter()->first()->active)->toBeTrue();

    app(App\Services\CustomCharacter\EquipRosterCharacter::class)->execute($user, App\Enums\FighterCharacter::Werebear);
    expect($user->customCharacter()->first()->active)->toBeFalse()
        ->and($user->fresh()->equipped_character)->toBe(App\Enums\FighterCharacter::Werebear);
    Event::assertDispatchedTimes(FighterCharacterChanged::class, 2);

    holdPlayersLock($user);
    expect(fn () => app(App\Services\CustomCharacter\EquipCustomCharacter::class)->execute($user))
        ->toThrow(App\Exceptions\CustomCharacterBusyException::class)
        ->and(fn () => app(App\Services\CustomCharacter\EquipRosterCharacter::class)->execute($user, App\Enums\FighterCharacter::Werebear))
        ->toThrow(App\Exceptions\CustomCharacterBusyException::class);
});

test('a player cannot confirm, equip or delete another players draft or version', function () {
    [$user] = userWithTwoVersions();
    $theirs = CustomCharacter::factory()->create();
    $theirDraft = stagedFor($theirs->user);

    expect(fn () => app(ConfirmCustomCharacter::class)->execute($user, $theirDraft->version_hash))
        ->toThrow(App\Exceptions\CustomCharacterDraftMissingException::class)
        ->and(fn () => app(DeleteCustomCharacterVersion::class)->execute($user, $theirs->version_hash))
        ->toThrow(CustomCharacterVersionException::class)
        ->and(fn () => app(EquipCustomCharacterVersion::class)->execute($user, $theirs->version_hash))
        ->toThrow(CustomCharacterVersionException::class);
});
