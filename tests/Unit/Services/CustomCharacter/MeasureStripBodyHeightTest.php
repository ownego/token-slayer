<?php

use App\Services\CustomCharacter\CustomCharacterBuilder;
use App\Services\CustomCharacter\MeasureStripBodyHeight;

/**
 * A transparent 600x100 strip.
 *
 * @return GdImage
 */
function transparentStrip(): GdImage
{
    $strip = imagecreatetruecolor(600, 100);
    imagealphablending($strip, false);
    imagesavealpha($strip, true);
    imagefill($strip, 0, 0, imagecolorallocatealpha($strip, 0, 0, 0, 127));

    return $strip;
}

test('counts the rows that hold ink in the first frame', function () {
    $strip = transparentStrip();
    imagefilledrectangle($strip, 40, 30, 49, 57, imagecolorallocatealpha($strip, 200, 10, 10, 0));

    expect(app(MeasureStripBodyHeight::class)->execute($strip))->toBe(28);
});

test('ignores ink in later frames', function () {
    $strip = transparentStrip();
    imagefilledrectangle($strip, 40, 30, 49, 57, imagecolorallocatealpha($strip, 200, 10, 10, 0));
    imagefilledrectangle($strip, 140, 5, 149, 90, imagecolorallocatealpha($strip, 200, 10, 10, 0));

    expect(app(MeasureStripBodyHeight::class)->execute($strip))->toBe(28);
});

test('a fully transparent frame has no body', function () {
    expect(app(MeasureStripBodyHeight::class)->execute(transparentStrip()))->toBe(0);
});

test('measures a freshly built idle strip at the configured height or a little over', function () {
    $idle = app(CustomCharacterBuilder::class)->execute(pngBytes(poseSheetImage(background: 'transparent')))['idle']['image'];

    $height = app(MeasureStripBodyHeight::class)->execute($idle);

    expect($height)->toBeGreaterThanOrEqual((int) config('token_slayer.custom_character.sprite_height'))
        ->and($height)->toBeLessThanOrEqual((int) config('token_slayer.custom_character.sprite_height') + 2);
});
