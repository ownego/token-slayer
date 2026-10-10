<?php

use App\Services\CustomCharacter\CustomCharacterBuilder;
use App\Services\CustomCharacter\CustomCharacterStore;
use Illuminate\Support\Facades\Storage;

/**
 * Strips built from a synthetic sheet.
 *
 * @param  string  $background  'magenta' or 'transparent'
 * @param  bool  $marked  paint a mark on a walk pose so the strips differ from the plain sheet
 * @return array<string, array{image: GdImage, frames: int, rate: int}>
 */
function builtStrips(string $background = 'transparent', bool $marked = false): array
{
    $sheet = poseSheetImage(background: $background);

    return app(CustomCharacterBuilder::class)->execute(pngBytes($marked ? markedPoseSheet($sheet) : $sheet));
}

beforeEach(function () {
    Storage::fake('public');
});

test('writes one png per animation under the user and version hash, and reports the animation table', function () {
    $saved = app(CustomCharacterStore::class)->put(7, builtStrips());

    expect($saved['hash'])->toMatch('/^[a-f0-9]{12}$/')
        ->and($saved['animations'])->toBe([
            'idle' => ['frames' => 6, 'rate' => 8],
            'walk' => ['frames' => 8, 'rate' => 10],
            'attack' => ['frames' => 6, 'rate' => 12],
            'death' => ['frames' => 3, 'rate' => 6],
        ]);

    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertExists("custom-characters/7/{$saved['hash']}/{$animation}.png");
    }

    $walk = imagecreatefromstring(Storage::disk('public')->get("custom-characters/7/{$saved['hash']}/walk.png"));
    expect(imagesx($walk))->toBe(800)->and(imagesy($walk))->toBe(100);
});

test('gives the same hash for the same strips and a different one when they change', function () {
    $store = app(CustomCharacterStore::class);

    $first = $store->put(7, builtStrips('transparent'))['hash'];
    $again = $store->put(7, builtStrips('transparent'))['hash'];
    $other = $store->put(7, builtStrips('transparent', true))['hash'];

    expect($again)->toBe($first)
        ->and($other)->not->toBe($first);
});

test('removes the previous version when a new one is stored', function () {
    $store = app(CustomCharacterStore::class);

    $old = $store->put(7, builtStrips('transparent'))['hash'];
    $new = $store->put(7, builtStrips('transparent', true))['hash'];

    Storage::disk('public')->assertMissing("custom-characters/7/{$old}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/7/{$new}/idle.png");
});

test('leaves other users untouched, and forgetting removes everything for that user', function () {
    $store = app(CustomCharacterStore::class);

    $mine = $store->put(7, builtStrips())['hash'];
    $theirs = $store->put(8, builtStrips('transparent', true))['hash'];

    $store->forget(7);

    Storage::disk('public')->assertMissing("custom-characters/7/{$mine}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/8/{$theirs}/idle.png");
});
