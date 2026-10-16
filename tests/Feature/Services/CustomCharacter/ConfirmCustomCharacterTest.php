<?php

use App\Events\FighterCharacterChanged;
use App\Exceptions\CustomCharacterDraftMissingException;
use App\Models\CustomCharacter;
use App\Models\CustomCharacterDraft;
use App\Models\User;
use App\Services\CustomCharacter\ConfirmCustomCharacter;
use App\Services\CustomCharacter\DiscardCustomCharacterDraft;
use App\Services\CustomCharacter\StageCustomCharacter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    Event::fake([FighterCharacterChanged::class]);
});

test('confirming makes the draft the user\'s equipped fighter, with a history row, and clears the draft', function () {
    $user = User::factory()->create();
    $draft = stagedFor($user);

    $character = app(ConfirmCustomCharacter::class)->execute($user, $draft->version_hash);

    expect($character->version_hash)->toBe($draft->version_hash)
        ->and($character->active)->toBeTrue()
        ->and($character->name)->toBe('Custom')
        ->and($character->versions()->pluck('version_hash')->all())->toBe([$draft->version_hash])
        ->and($user->fresh()->customCharacterDraft)->toBeNull();
    Storage::disk('public')->assertExists("custom-characters/{$user->id}/{$draft->version_hash}/idle.png");
    Storage::disk('public')->assertMissing("custom-characters/{$user->id}/staged/{$draft->version_hash}/idle.png");
});

test('confirming tells the battlefield', function () {
    $user = User::factory()->create();

    app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user)->version_hash);

    Event::assertDispatched(FighterCharacterChanged::class, fn ($event) => $event->user->is($user) && $event->user->customFighterState() !== null);
});

test('confirming twice is harmless: the second one returns the same fighter', function () {
    $user = User::factory()->create();
    $hash = stagedFor($user)->version_hash;
    $confirm = app(ConfirmCustomCharacter::class);

    $first = $confirm->execute($user, $hash);
    $second = $confirm->execute($user, $hash);

    expect($second->is($first))->toBeTrue()
        ->and(CustomCharacter::count())->toBe(1)
        ->and($second->versions()->count())->toBe(1);
});

test('there is nothing to confirm without a draft of that version', function () {
    $user = User::factory()->create();

    expect(fn () => app(ConfirmCustomCharacter::class)->execute($user, 'abcdefabcdef'))->toThrow(CustomCharacterDraftMissingException::class);

    stagedFor($user);
    expect(fn () => app(ConfirmCustomCharacter::class)->execute($user, 'abcdefabcdef'))->toThrow(CustomCharacterDraftMissingException::class);
});

test('a user can only confirm their own draft, never one staged by someone else', function () {
    [$owner, $other] = User::factory()->count(2)->create();
    $draft = stagedFor($owner);

    expect(fn () => app(ConfirmCustomCharacter::class)->execute($other, $draft->version_hash))->toThrow(CustomCharacterDraftMissingException::class);

    expect($other->fresh()->customCharacter)->toBeNull()
        ->and($owner->fresh()->customCharacterDraft->version_hash)->toBe($draft->version_hash);
});

test('a second confirmed sheet becomes current and the first stays in the history, files and all', function () {
    $user = User::factory()->create();
    $confirm = app(ConfirmCustomCharacter::class);
    $first = $confirm->execute($user, stagedFor($user, 0)->version_hash);
    $firstHash = $first->version_hash;

    $second = $confirm->execute($user, stagedFor($user, 1)->version_hash);

    expect($second->version_hash)->not->toBe($firstHash)
        ->and($second->versions()->pluck('version_hash')->all())->toEqualCanonicalizing([$firstHash, $second->version_hash])
        ->and(CustomCharacter::count())->toBe(1);
    Storage::disk('public')->assertExists("custom-characters/{$user->id}/{$firstHash}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/{$user->id}/{$second->version_hash}/idle.png");
});

test('confirming keeps the name the player chose', function () {
    $user = User::factory()->create();
    $confirm = app(ConfirmCustomCharacter::class);
    $confirm->execute($user, stagedFor($user, 0)->version_hash);
    $user->customCharacter->update(['name' => 'Sir Pixel']);

    $second = $confirm->execute($user, stagedFor($user, 1)->version_hash);

    expect($second->name)->toBe('Sir Pixel');
});

test('confirming equips the custom fighter again after the player had gone back to a roster character', function () {
    $user = User::factory()->create();
    $confirm = app(ConfirmCustomCharacter::class);
    $confirm->execute($user, stagedFor($user, 0)->version_hash);
    $user->customCharacter->update(['active' => false]);

    $character = $confirm->execute($user, stagedFor($user, 1)->version_hash);

    expect($character->active)->toBeTrue();
});

test('the history holds only the newest few versions: the oldest is dropped with its files', function () {
    config(['token_slayer.custom_character.max_versions' => 2]);
    $user = User::factory()->create();
    $confirm = app(ConfirmCustomCharacter::class);
    $a = $confirm->execute($user, stagedFor($user, 0)->version_hash)->version_hash;
    $b = $confirm->execute($user, stagedFor($user, 1)->version_hash)->version_hash;

    $character = $confirm->execute($user, stagedFor($user, 2)->version_hash);

    expect($character->versions()->pluck('version_hash')->all())->toEqualCanonicalizing([$b, $character->version_hash]);
    Storage::disk('public')->assertMissing("custom-characters/{$user->id}/{$a}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/{$user->id}/{$b}/idle.png");
});

test('discarding drops the draft and its files and leaves the live fighter', function () {
    $user = User::factory()->create();
    $live = app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user, 0)->version_hash);
    $draft = stagedFor($user, 1);

    app(DiscardCustomCharacterDraft::class)->execute($user);

    expect($user->fresh()->customCharacterDraft)->toBeNull()
        ->and($user->fresh()->customCharacter->version_hash)->toBe($live->version_hash);
    Storage::disk('public')->assertMissing("custom-characters/{$user->id}/staged/{$draft->version_hash}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/{$user->id}/{$live->version_hash}/idle.png");
});
