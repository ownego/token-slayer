<?php

use App\Exceptions\InvalidPoseSheetException;
use App\Models\CustomCharacter;
use App\Models\User;
use App\Services\CustomCharacter\RemoveCustomCharacter;
use App\Services\CustomCharacter\SaveCustomCharacter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

test('builds, stores and records the user\'s character', function () {
    $user = User::factory()->create();

    $character = app(SaveCustomCharacter::class)->execute($user, pngBytes(poseSheetImage(background: 'transparent')));

    expect($character->user_id)->toBe($user->id)
        ->and($character->animations['walk'])->toBe(['frames' => 8, 'rate' => 10])
        ->and($user->fresh()->customCharacter->version_hash)->toBe($character->version_hash);

    Storage::disk('public')->assertExists("custom-characters/{$user->id}/{$character->version_hash}/idle.png");
});

test('replaces the user\'s existing character instead of adding a second', function () {
    $user = User::factory()->create();
    $save = app(SaveCustomCharacter::class);

    $first = $save->execute($user, pngBytes(poseSheetImage(background: 'transparent')));
    $second = $save->execute($user, pngBytes(markedPoseSheet(poseSheetImage(background: 'transparent'))));

    expect(CustomCharacter::count())->toBe(1)
        ->and($second->version_hash)->not->toBe($first->version_hash);

    Storage::disk('public')->assertMissing("custom-characters/{$user->id}/{$first->version_hash}/idle.png");
});

test('keeps the existing character when the new sheet is rejected', function () {
    $user = User::factory()->create();
    $save = app(SaveCustomCharacter::class);
    $existing = $save->execute($user, pngBytes(poseSheetImage(background: 'transparent')));

    expect(fn () => $save->execute($user, pngBytes(poseSheetImage([4, 4, 3], 'transparent', 200))))
        ->toThrow(InvalidPoseSheetException::class);

    expect($user->fresh()->customCharacter->version_hash)->toBe($existing->version_hash);
    Storage::disk('public')->assertExists("custom-characters/{$user->id}/{$existing->version_hash}/idle.png");
});

test('removes the character and its files', function () {
    $user = User::factory()->create();
    $character = app(SaveCustomCharacter::class)->execute($user, pngBytes(poseSheetImage(background: 'transparent')));

    app(RemoveCustomCharacter::class)->execute($user);

    expect($user->fresh()->customCharacter)->toBeNull();
    Storage::disk('public')->assertMissing("custom-characters/{$user->id}/{$character->version_hash}/idle.png");
});
