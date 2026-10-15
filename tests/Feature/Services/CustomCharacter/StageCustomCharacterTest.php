<?php

use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterStorageException;
use App\Exceptions\InvalidPoseSheetException;
use App\Models\CustomCharacter;
use App\Models\User;
use App\Services\CustomCharacter\StageCustomCharacter;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

test('stages the sheet as a draft and leaves the live character alone', function () {
    $user = User::factory()->create();
    $live = CustomCharacter::factory()->create(['user_id' => $user->id]);

    $draft = app(StageCustomCharacter::class)->execute($user, pngBytes(poseSheetImage(background: 'transparent')));

    expect($draft->user_id)->toBe($user->id)
        ->and($draft->animations['walk'])->toBe(['frames' => 8, 'rate' => 10])
        ->and($user->fresh()->customCharacterDraft->version_hash)->toBe($draft->version_hash)
        ->and($user->fresh()->customCharacter->version_hash)->toBe($live->version_hash);
    Storage::disk('public')->assertExists("custom-characters/{$user->id}/staged/{$draft->version_hash}/idle.png");
});

test('a new sheet replaces the earlier draft instead of adding a second, and its files go', function () {
    $user = User::factory()->create();
    $stage = app(StageCustomCharacter::class);

    $first = $stage->execute($user, pngBytes(poseSheetImage(background: 'transparent')));
    $second = $stage->execute($user, pngBytes(markedPoseSheet(poseSheetImage(background: 'transparent'))));

    expect($user->customCharacterDraft()->count())->toBe(1)
        ->and($second->version_hash)->not->toBe($first->version_hash);
    Storage::disk('public')->assertMissing("custom-characters/{$user->id}/staged/{$first->version_hash}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/{$user->id}/staged/{$second->version_hash}/idle.png");
});

test('a refused sheet keeps the earlier draft and the live character', function () {
    $user = User::factory()->create();
    $stage = app(StageCustomCharacter::class);
    $draft = $stage->execute($user, pngBytes(poseSheetImage(background: 'transparent')));
    $live = CustomCharacter::factory()->create(['user_id' => $user->id]);

    expect(fn () => $stage->execute($user, pngBytes(poseSheetImage([4, 4, 3], 'transparent', 200))))
        ->toThrow(InvalidPoseSheetException::class);

    expect($user->fresh()->customCharacterDraft->version_hash)->toBe($draft->version_hash)
        ->and($user->fresh()->customCharacter->version_hash)->toBe($live->version_hash);
    Storage::disk('public')->assertExists("custom-characters/{$user->id}/staged/{$draft->version_hash}/idle.png");
});

test('a write that fails leaves the earlier draft untouched', function () {
    $user = User::factory()->create();
    $stage = app(StageCustomCharacter::class);
    $draft = $stage->execute($user, pngBytes(poseSheetImage(background: 'transparent')));
    config([
        'filesystems.disks.unwritable' => ['driver' => 'local', 'root' => '/proc/no-such-place', 'throw' => false],
        'token_slayer.custom_character.disk' => 'unwritable',
    ]);

    expect(fn () => $stage->execute($user, pngBytes(markedPoseSheet(poseSheetImage(background: 'transparent')))))
        ->toThrow(CustomCharacterStorageException::class);

    expect($user->fresh()->customCharacterDraft->version_hash)->toBe($draft->version_hash);
});

test('two builds by one user do not run at once: the second is turned away, not interleaved', function () {
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    $user = User::factory()->create();
    $held = Cache::lock(CacheKeys::customCharacterBuild($user->id), 30);
    $held->get();

    expect(fn () => app(StageCustomCharacter::class)->execute($user, pngBytes(poseSheetImage(background: 'transparent'))))
        ->toThrow(CustomCharacterBusyException::class);

    $held->release();
    expect(app(StageCustomCharacter::class)->execute($user, pngBytes(poseSheetImage(background: 'transparent')))->user_id)->toBe($user->id);
});

test('another user building at the same time is not held up', function () {
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    [$one, $two] = User::factory()->count(2)->create();
    $held = Cache::lock(CacheKeys::customCharacterBuild($one->id), 30);
    $held->get();

    expect(app(StageCustomCharacter::class)->execute($two, pngBytes(poseSheetImage(background: 'transparent')))->user_id)->toBe($two->id);
});
