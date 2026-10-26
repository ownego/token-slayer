<?php

use App\Exceptions\CustomCharacterStorageException;
use App\Services\CustomCharacter\CustomCharacterStore;
use App\Services\CustomCharacter\CustomMinionStore;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

test('stages and promotes minions under custom-minions', function () {
    $store = app(CustomMinionStore::class);

    $hash = $store->stage(7, builtStrips())['hash'];
    Storage::disk('public')->assertExists("custom-minions/7/staged/{$hash}/idle.png");

    $store->promote(7, $hash);

    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertExists("custom-minions/7/{$hash}/{$animation}.png");
    }
    Storage::disk('public')->assertMissing("custom-minions/7/staged/{$hash}/idle.png");
    Storage::disk('public')->assertMissing("custom-characters/7/{$hash}/idle.png");
    expect($store->baseUrl(7, $hash))->toBe("/storage/custom-minions/7/{$hash}")
        ->and($store->baseUrl(7, $hash, staged: true))->toBe("/storage/custom-minions/7/staged/{$hash}")
        ->and(CustomMinionStore::folder(7, $hash))->toBe("custom-minions/7/{$hash}")
        ->and(CustomMinionStore::stagedFolder(7, $hash))->toBe("custom-minions/7/staged/{$hash}");
});

test('leaves minion files alone when the fighter store prunes, discards or forgets', function () {
    $minions = app(CustomMinionStore::class);
    $fighters = app(CustomCharacterStore::class);
    $minion = $minions->stage(7, builtStrips())['hash'];
    $minions->promote(7, $minion);
    $fighter = $fighters->stage(7, builtStrips('transparent', true))['hash'];
    $fighters->promote(7, $fighter);

    $fighters->prune(7, []);
    $fighters->discardStaged(7);
    $fighters->forget(7);

    Storage::disk('public')->assertMissing("custom-characters/7/{$fighter}/idle.png");
    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertExists("custom-minions/7/{$minion}/{$animation}.png");
    }
});

test('the minion store sweeps only the minion root', function () {
    $minions = app(CustomMinionStore::class);
    $fighters = app(CustomCharacterStore::class);
    $fighter = $fighters->stage(7, builtStrips())['hash'];
    $fighters->promote(7, $fighter);
    $minion = $minions->stage(7, builtStrips('transparent', true))['hash'];
    $minions->promote(7, $minion);

    $minions->prune(7, []);
    $minions->discardStaged(7);
    $minions->forget(7);

    Storage::disk('public')->assertMissing("custom-minions/7/{$minion}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/7/{$fighter}/idle.png");
});

test('copying a staged minion leaves the staged files where they are', function () {
    $store = app(CustomMinionStore::class);
    $hash = $store->stage(7, builtStrips())['hash'];

    $store->copyStaged(7, $hash);

    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertExists("custom-minions/7/{$hash}/{$animation}.png");
        Storage::disk('public')->assertExists("custom-minions/7/staged/{$hash}/{$animation}.png");
        expect(Storage::disk('public')->get("custom-minions/7/{$hash}/{$animation}.png"))
            ->toBe(Storage::disk('public')->get("custom-minions/7/staged/{$hash}/{$animation}.png"));
    }
});

test('copying a staged minion that is already in place copies nothing', function () {
    $store = app(CustomMinionStore::class);
    $hash = $store->stage(7, builtStrips())['hash'];
    $store->copyStaged(7, $hash);
    breakPublicDisk('copy', fn () => true);

    $store->copyStaged(7, $hash);

    Storage::disk('public')->assertExists("custom-minions/7/{$hash}/idle.png");
});

test('copying a version that was never staged fails loudly and creates nothing', function () {
    expect(fn () => app(CustomMinionStore::class)->copyStaged(7, 'abcdefabcdef'))
        ->toThrow(CustomCharacterStorageException::class, "The built sprite's files are gone. Please build it again.");

    Storage::disk('public')->assertMissing('custom-minions/7/abcdefabcdef/idle.png');
});

test('a failed copy of a staged minion keeps the staged files whole and leaves no half folder', function () {
    $store = app(CustomMinionStore::class);
    $hash = $store->stage(7, builtStrips())['hash'];
    breakPublicDisk('copy', fn (string $from) => str_ends_with($from, 'walk.png'));

    expect(fn () => $store->copyStaged(7, $hash))->toThrow(CustomCharacterStorageException::class);

    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertExists("custom-minions/7/staged/{$hash}/{$animation}.png");
        Storage::disk('public')->assertMissing("custom-minions/7/{$hash}/{$animation}.png");
    }
});

test('adopts a fighter version into the minion staged area and leaves the fighter alone', function () {
    $fighters = app(CustomCharacterStore::class);
    $hash = $fighters->stage(7, builtStrips())['hash'];
    $fighters->promote(7, $hash);

    app(CustomMinionStore::class)->adoptStaged(7, $hash, CustomCharacterStore::folder(7, $hash));

    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertExists("custom-minions/7/staged/{$hash}/{$animation}.png");
        Storage::disk('public')->assertExists("custom-characters/7/{$hash}/{$animation}.png");
        expect(Storage::disk('public')->get("custom-minions/7/staged/{$hash}/{$animation}.png"))
            ->toBe(Storage::disk('public')->get("custom-characters/7/{$hash}/{$animation}.png"));
    }
    Storage::disk('public')->assertMissing("custom-minions/7/{$hash}/idle.png");
});

test('adopting a version that is already staged changes nothing', function () {
    $fighters = app(CustomCharacterStore::class);
    $minions = app(CustomMinionStore::class);
    $hash = $fighters->stage(7, builtStrips())['hash'];
    $fighters->promote(7, $hash);
    $minions->adoptStaged(7, $hash, CustomCharacterStore::folder(7, $hash));
    breakPublicDisk('copy', fn () => true);

    $minions->adoptStaged(7, $hash, CustomCharacterStore::folder(7, $hash));

    Storage::disk('public')->assertExists("custom-minions/7/staged/{$hash}/idle.png");
});

test('adopting from a folder that lacks strips fails loudly and creates nothing', function () {
    expect(fn () => app(CustomMinionStore::class)->adoptStaged(7, 'abcdefabcdef', 'custom-characters/7/abcdefabcdef'))
        ->toThrow(CustomCharacterStorageException::class);

    Storage::disk('public')->assertMissing('custom-minions/7/staged/abcdefabcdef/idle.png');
});

test('removes a half-adopted folder when a copy fails', function () {
    $fighters = app(CustomCharacterStore::class);
    $hash = $fighters->stage(7, builtStrips())['hash'];
    $fighters->promote(7, $hash);
    breakPublicDisk('copy', fn (string $from) => str_ends_with($from, 'walk.png'));

    expect(fn () => app(CustomMinionStore::class)->adoptStaged(7, $hash, CustomCharacterStore::folder(7, $hash)))
        ->toThrow(CustomCharacterStorageException::class);

    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertMissing("custom-minions/7/staged/{$hash}/{$animation}.png");
        Storage::disk('public')->assertExists("custom-characters/7/{$hash}/{$animation}.png");
    }
});

test('a slow adopt aborts before the lock expires, without waiting for the clock', function () {
    $fighters = app(CustomCharacterStore::class);
    $hash = $fighters->stage(7, builtStrips())['hash'];
    $fighters->promote(7, $hash);
    // with the lock held for 5 s and 5 s kept in reserve, no time at all is left to spend
    config(['token_slayer.custom_character.lock_hold_seconds' => 5]);

    expect(fn () => app(CustomMinionStore::class)->adoptStaged(7, $hash, CustomCharacterStore::folder(7, $hash)))
        ->toThrow(CustomCharacterStorageException::class, 'The storage is too slow right now');
    Storage::disk('public')->assertMissing("custom-minions/7/staged/{$hash}/idle.png");
});
