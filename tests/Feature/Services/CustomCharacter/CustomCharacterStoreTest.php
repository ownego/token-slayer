<?php

use App\Exceptions\CustomCharacterStorageException;
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

test('stages one png per animation under the user and version hash, apart from confirmed ones', function () {
    $staged = app(CustomCharacterStore::class)->stage(7, builtStrips());

    expect($staged['hash'])->toMatch('/^[a-f0-9]{12}$/')
        ->and($staged['animations'])->toBe([
            'idle' => ['frames' => 6, 'rate' => 8],
            'walk' => ['frames' => 8, 'rate' => 10],
            'attack' => ['frames' => 6, 'rate' => 12],
            'death' => ['frames' => 3, 'rate' => 6],
        ]);

    foreach (['idle', 'walk', 'attack', 'death'] as $animation) {
        Storage::disk('public')->assertExists("custom-characters/7/staged/{$staged['hash']}/{$animation}.png");
    }
    Storage::disk('public')->assertMissing("custom-characters/7/{$staged['hash']}/idle.png");

    $walk = imagecreatefromstring(Storage::disk('public')->get("custom-characters/7/staged/{$staged['hash']}/walk.png"));
    expect(imagesx($walk))->toBe(800)->and(imagesy($walk))->toBe(100);
});

test('gives the same hash for the same strips and a different one when they change', function () {
    $store = app(CustomCharacterStore::class);

    $first = $store->stage(7, builtStrips('transparent'))['hash'];
    $again = $store->stage(7, builtStrips('transparent'))['hash'];
    $other = $store->stage(7, builtStrips('transparent', true))['hash'];

    expect($again)->toBe($first)->and($other)->not->toBe($first);
});

test('promoting moves a staged version into the confirmed folder', function () {
    $store = app(CustomCharacterStore::class);
    $hash = $store->stage(7, builtStrips())['hash'];

    $store->promote(7, $hash);

    Storage::disk('public')->assertExists("custom-characters/7/{$hash}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/7/{$hash}/death.png");
    Storage::disk('public')->assertMissing("custom-characters/7/staged/{$hash}/idle.png");
});

test('promoting a version that is already confirmed just drops the staged copy', function () {
    $store = app(CustomCharacterStore::class);
    $hash = $store->stage(7, builtStrips())['hash'];
    $store->promote(7, $hash);
    $store->stage(7, builtStrips());

    $store->promote(7, $hash);

    Storage::disk('public')->assertExists("custom-characters/7/{$hash}/idle.png");
    Storage::disk('public')->assertMissing("custom-characters/7/staged/{$hash}/idle.png");
});

test('promoting something that was never staged fails loudly', function () {
    expect(fn () => app(CustomCharacterStore::class)->promote(7, 'abcdefabcdef'))->toThrow(CustomCharacterStorageException::class);
});

test('pruning the staged area keeps only the version asked for', function () {
    $store = app(CustomCharacterStore::class);
    $old = $store->stage(7, builtStrips('transparent'))['hash'];
    $new = $store->stage(7, builtStrips('transparent', true))['hash'];

    $store->pruneStaged(7, $new);

    Storage::disk('public')->assertMissing("custom-characters/7/staged/{$old}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/7/staged/{$new}/idle.png");
});

test('discarding the staged area removes it and nothing confirmed', function () {
    $store = app(CustomCharacterStore::class);
    $confirmed = $store->stage(7, builtStrips('transparent'))['hash'];
    $store->promote(7, $confirmed);
    $staged = $store->stage(7, builtStrips('transparent', true))['hash'];

    $store->discardStaged(7);

    Storage::disk('public')->assertMissing("custom-characters/7/staged/{$staged}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/7/{$confirmed}/idle.png");
});

test('pruning keeps the listed confirmed versions, drops the others, and never touches staged files', function () {
    $store = app(CustomCharacterStore::class);
    $a = $store->stage(7, builtStrips('transparent'))['hash'];
    $store->promote(7, $a);
    $b = $store->stage(7, builtStrips('transparent', true))['hash'];
    $store->promote(7, $b);
    $pending = $store->stage(7, builtStrips('magenta'))['hash'];

    $store->prune(7, [$b]);

    Storage::disk('public')->assertMissing("custom-characters/7/{$a}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/7/{$b}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/7/staged/{$pending}/idle.png");
});

test('forgetting one version leaves the rest, and forgetting a user removes everything for them only', function () {
    $store = app(CustomCharacterStore::class);
    $a = $store->stage(7, builtStrips('transparent'))['hash'];
    $store->promote(7, $a);
    $b = $store->stage(7, builtStrips('transparent', true))['hash'];
    $store->promote(7, $b);
    $theirs = $store->stage(8, builtStrips('magenta'))['hash'];
    $store->promote(8, $theirs);

    $store->forgetVersion(7, $a);
    Storage::disk('public')->assertMissing("custom-characters/7/{$a}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/7/{$b}/idle.png");

    $store->forget(7);
    Storage::disk('public')->assertMissing("custom-characters/7/{$b}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/8/{$theirs}/idle.png");
});

test('a write that fails is an error, not silence', function () {
    config([
        'filesystems.disks.unwritable' => ['driver' => 'local', 'root' => '/proc/no-such-place', 'throw' => false],
        'token_slayer.custom_character.disk' => 'unwritable',
    ]);

    expect(fn () => app(CustomCharacterStore::class)->stage(7, builtStrips()))->toThrow(CustomCharacterStorageException::class);
});

test('the strip URLs and base are same-origin paths on the public disk, staged ones under staged/', function () {
    $store = app(CustomCharacterStore::class);

    expect($store->baseUrl(7, 'abcdefabcdef'))->toBe('/storage/custom-characters/7/abcdefabcdef')
        ->and($store->baseUrl(7, 'abcdefabcdef', staged: true))->toBe('/storage/custom-characters/7/staged/abcdefabcdef')
        ->and($store->stripUrls(7, 'abcdefabcdef'))->toBe([
            'idle' => '/storage/custom-characters/7/abcdefabcdef/idle.png',
            'walk' => '/storage/custom-characters/7/abcdefabcdef/walk.png',
            'attack' => '/storage/custom-characters/7/abcdefabcdef/attack.png',
            'death' => '/storage/custom-characters/7/abcdefabcdef/death.png',
        ]);
});

test('a disk served from another host gives a full URL, so moving the strips to a bucket is a config change', function () {
    config([
        'filesystems.disks.cdn' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/cdn'), 'url' => 'https://cdn.example.com/media', 'visibility' => 'public'],
        'token_slayer.custom_character.disk' => 'cdn',
    ]);

    expect(app(CustomCharacterStore::class)->baseUrl(7, 'abcdefabcdef'))->toBe('https://cdn.example.com/media/custom-characters/7/abcdefabcdef');
});
