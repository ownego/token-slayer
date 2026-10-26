<?php

use App\Models\CustomCharacter;
use App\Models\CustomCharacterDraft;
use App\Models\CustomCharacterVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the broadcast state names the version, a same-origin url base and the animation table', function () {
    $character = CustomCharacter::factory()->create(['version_hash' => 'abc123def456']);

    expect($character->broadcastState())->toBe([
        'version' => 'abc123def456',
        'url_base' => "/storage/custom-characters/{$character->user_id}/abc123def456",
        'animations' => $character->animations,
    ]);
});

test('a user with no custom character has no broadcast state', function () {
    expect(User::factory()->create()->customFighterState())->toBeNull();
});

test('a user with a custom character exposes its broadcast state', function () {
    $character = CustomCharacter::factory()->create();

    expect($character->user->customFighterState())->toBe($character->broadcastState());
});

test('a custom character is equipped when it is built', function () {
    expect(CustomCharacter::factory()->create()->active)->toBeTrue();
});

test('an unequipped custom character is not what the battlefield shows', function () {
    $character = CustomCharacter::factory()->create(['active' => false]);

    expect($character->user->customFighterState())->toBeNull()
        ->and($character->broadcastState())->toBeArray();
});

test('the sheet state names the author, and the public broadcast state never does', function () {
    $character = CustomCharacter::factory()->create(['name' => 'Sir Pixel']);

    expect($character->sheetState())->toMatchArray([
        'name' => 'Sir Pixel',
        'author' => $character->user->displayHandle(),
    ])->and($character->broadcastState())->not->toHaveKey('author');
});

test('the fighter in use is one of its own versions, and earlier versions are listed with it', function () {
    $character = CustomCharacter::factory()->create(['version_hash' => 'current00000']);
    CustomCharacterVersion::factory()->for($character->user)->create(['version_hash' => 'earlier00000']);

    expect($character->versions()->pluck('version_hash')->all())->toBe(['current00000', 'earlier00000'])
        ->and(CustomCharacter::count())->toBe(1)
        ->and(CustomCharacterVersion::count())->toBe(2);
});

test('a draft is not the fighter in use, and another player\'s versions are not this one\'s', function () {
    $character = CustomCharacter::factory()->create();
    CustomCharacterDraft::create(['user_id' => $character->user_id, 'version_hash' => 'draft0000000', 'animations' => $character->animations]);
    CustomCharacter::factory()->create();

    expect(CustomCharacter::count())->toBe(2)
        ->and($character->user->customCharacter->is($character))->toBeTrue()
        ->and($character->versions()->count())->toBe(1);
});
