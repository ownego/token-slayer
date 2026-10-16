<?php

use App\Enums\PoseSheetProblem;
use App\Exceptions\InvalidPoseSheetException;
use App\Services\CustomCharacter\CustomCharacterBuilder;

/**
 * A sheet at the configured ceiling, noisy like a real image AI's: the poses plus
 * scattered single pixels, which the stray-mark pass has to track one by one.
 *
 * @param  int  $side  the sheet's width and height
 * @return string PNG bytes
 */
function noisySheet(int $side): string
{
    $image = poseSheetImage(cell: intdiv($side, 4), background: 'magenta');
    $ink = imagecolorallocate($image, 120, 60, 20);
    mt_srand(7);
    for ($i = 0; $i < 40_000; $i++) {
        imagesetpixel($image, mt_rand(0, $side - 1), mt_rand(0, $side - 1), $ink);
    }

    return pngBytes($image);
}

test('the configured ceiling is the size the build was measured to survive', function () {
    expect(config('token_slayer.custom_character.max_side'))->toBe(2048)
        ->and(config('token_slayer.custom_character.max_pixels'))->toBe(2048 * 2048);
});

test('a sheet at the ceiling builds well inside PHP\'s default 256M memory limit', function () {
    $bytes = noisySheet(2048);
    memory_reset_peak_usage();

    app(CustomCharacterBuilder::class)->execute($bytes);

    // measured: about 115 MB for the build itself; the old 4000 px ceiling peaked near 230 MB and could die
    expect(memory_get_peak_usage(true) / 1048576)->toBeLessThan(200);
});

test('a sheet over the ceiling is refused up front, before a pixel is decoded', function () {
    $bytes = noisySheet(2048);
    config(['token_slayer.custom_character.max_pixels' => 2048 * 2048 - 1]);

    expect(fn () => app(CustomCharacterBuilder::class)->execute($bytes))
        ->toThrow(fn (InvalidPoseSheetException $e) => expect($e->problem)->toBe(PoseSheetProblem::DimensionsTooLarge));
});
