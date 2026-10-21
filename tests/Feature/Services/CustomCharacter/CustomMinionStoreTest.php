<?php

use App\Exceptions\CustomCharacterStorageException;
use App\Services\CustomCharacter\CustomCharacterStore;
use App\Services\CustomCharacter\CustomMinionStore;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

/**
 * Wraps the faked public disk so some calls fail, everything else going to the real fake.
 *
 * @param  string  $method  the disk method to break
 * @param  Closure  $when  receives the call's arguments; true makes that call fail
 * @return void
 */
function breakMinionDisk(string $method, Closure $when): void
{
    $real = Storage::disk('public');
    $disk = Mockery::mock($real);
    $disk->shouldReceive($method)->andReturnUsing(function (...$arguments) use ($real, $method, $when) {
        if ($when(...$arguments)) {
            return false;
        }

        return $real->{$method}(...$arguments);
    });
    Storage::set('public', $disk);
}

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

test('adopts a fighter version into the minion root', function () {
    $fighters = app(CustomCharacterStore::class);
    $hash = $fighters->stage(7, builtStrips())['hash'];
    $fighters->promote(7, $hash);

    app(CustomMinionStore::class)->adopt(7, $hash, CustomCharacterStore::folder(7, $hash));

    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertExists("custom-minions/7/{$hash}/{$animation}.png");
        Storage::disk('public')->assertExists("custom-characters/7/{$hash}/{$animation}.png");
        expect(Storage::disk('public')->get("custom-minions/7/{$hash}/{$animation}.png"))
            ->toBe(Storage::disk('public')->get("custom-characters/7/{$hash}/{$animation}.png"));
    }
});

test('adopting a version that is already in the minion root changes nothing', function () {
    $fighters = app(CustomCharacterStore::class);
    $minions = app(CustomMinionStore::class);
    $hash = $fighters->stage(7, builtStrips())['hash'];
    $fighters->promote(7, $hash);
    $minions->adopt(7, $hash, CustomCharacterStore::folder(7, $hash));
    breakMinionDisk('copy', fn () => true);

    $minions->adopt(7, $hash, CustomCharacterStore::folder(7, $hash));

    Storage::disk('public')->assertExists("custom-minions/7/{$hash}/idle.png");
});

test('adopting from a folder that lacks strips fails loudly and creates nothing', function () {
    expect(fn () => app(CustomMinionStore::class)->adopt(7, 'abcdefabcdef', 'custom-characters/7/abcdefabcdef'))
        ->toThrow(CustomCharacterStorageException::class);

    Storage::disk('public')->assertMissing('custom-minions/7/abcdefabcdef/idle.png');
});

test('removes a half-adopted folder when a copy fails', function () {
    $fighters = app(CustomCharacterStore::class);
    $hash = $fighters->stage(7, builtStrips())['hash'];
    $fighters->promote(7, $hash);
    breakMinionDisk('copy', fn (string $from) => str_ends_with($from, 'walk.png'));

    expect(fn () => app(CustomMinionStore::class)->adopt(7, $hash, CustomCharacterStore::folder(7, $hash)))
        ->toThrow(CustomCharacterStorageException::class);

    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertMissing("custom-minions/7/{$hash}/{$animation}.png");
        Storage::disk('public')->assertExists("custom-characters/7/{$hash}/{$animation}.png");
    }
});

test('a slow adopt aborts before the lock expires', function () {
    config(['token_slayer.custom_character.lock_hold_seconds' => 6]);
    $fighters = app(CustomCharacterStore::class);
    $hash = $fighters->stage(7, builtStrips())['hash'];
    $fighters->promote(7, $hash);
    $real = Storage::disk('public');
    $slow = Mockery::mock($real);
    $slow->shouldReceive('copy')->andReturnUsing(function (string $from, string $to) use ($real): bool {
        usleep(1_100_000);

        return $real->copy($from, $to);
    });
    Storage::set('public', $slow);

    expect(fn () => app(CustomMinionStore::class)->adopt(7, $hash, CustomCharacterStore::folder(7, $hash)))
        ->toThrow(CustomCharacterStorageException::class, 'The storage is too slow right now');
    $real->assertMissing("custom-minions/7/{$hash}/idle.png");
});
