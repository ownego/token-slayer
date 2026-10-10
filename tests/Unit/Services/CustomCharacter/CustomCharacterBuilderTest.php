<?php

use App\Enums\PoseSheetProblem;
use App\Exceptions\InvalidPoseSheetException;
use App\Services\CustomCharacter\CustomCharacterBuilder;

test('turns a valid pose sheet into the four fighter strips', function (string $background) {
    $strips = app(CustomCharacterBuilder::class)->execute(pngBytes(poseSheetImage(background: $background)));

    expect(array_keys($strips))->toBe(['idle', 'walk', 'attack', 'death'])
        ->and(array_map(fn (array $strip) => $strip['frames'], $strips))->toBe(['idle' => 6, 'walk' => 8, 'attack' => 6, 'death' => 3]);

    foreach ($strips as $strip) {
        expect(imagesx($strip['image']))->toBe($strip['frames'] * 100)
            ->and(imagesy($strip['image']))->toBe(100);
    }
})->with([
    'magenta background' => ['magenta'],
    'transparent background' => ['transparent'],
]);

test('draws the outline colour around the character', function () {
    config(['token_slayer.custom_character.outline_color' => '#102030']);

    $idle = app(CustomCharacterBuilder::class)->execute(pngBytes(poseSheetImage(background: 'transparent')))['idle']['image'];

    $seen = [];
    for ($y = 0; $y < 100; $y++) {
        for ($x = 0; $x < 100; $x++) {
            $argb = imagecolorat($idle, $x, $y);
            if ((($argb >> 24) & 0x7F) === 0) {
                $seen[$argb & 0xFFFFFF] = true;
            }
        }
    }

    expect($seen)->toHaveKey(0x102030);
});

test('rejects a sheet that breaks the contract, naming the problem', function () {
    $problem = null;

    try {
        app(CustomCharacterBuilder::class)->execute(pngBytes(poseSheetImage([4, 4, 3], 'transparent', 200)));
    } catch (InvalidPoseSheetException $exception) {
        $problem = $exception->problem;
    }

    expect($problem)->toBe(PoseSheetProblem::WrongRowCount);
});

test('rejects bytes that are not an image before doing any work', function () {
    $problem = null;

    try {
        app(CustomCharacterBuilder::class)->execute('not an image');
    } catch (InvalidPoseSheetException $exception) {
        $problem = $exception->problem;
    }

    expect($problem)->toBe(PoseSheetProblem::NotAnImage);
});
