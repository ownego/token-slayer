<?php

use App\Exceptions\BlankStripException;
use App\Services\CustomCharacter\CustomCharacterBuilder;
use App\Services\CustomCharacter\MeasureStripBodyHeight;
use App\Services\CustomCharacter\SpriteStripBuilder;

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

test('measures from the first to the last inked row', function () {
    $strip = transparentStrip();
    $ink = imagecolorallocatealpha($strip, 200, 10, 10, 0);
    imagefilledrectangle($strip, 40, 30, 49, 35, $ink);
    imagefilledrectangle($strip, 40, 44, 49, 57, $ink);

    expect((new MeasureStripBodyHeight)->execute($strip))->toBe(28);
});

test('a body one row tall measures one', function () {
    $strip = transparentStrip();
    imagesetpixel($strip, 10, 57, imagecolorallocatealpha($strip, 200, 10, 10, 0));

    expect((new MeasureStripBodyHeight)->execute($strip))->toBe(1);
});

test('ignores ink in later frames', function () {
    $strip = transparentStrip();
    imagefilledrectangle($strip, 40, 30, 49, 57, imagecolorallocatealpha($strip, 200, 10, 10, 0));
    imagefilledrectangle($strip, 140, 5, 149, 90, imagecolorallocatealpha($strip, 200, 10, 10, 0));

    expect((new MeasureStripBodyHeight)->execute($strip))->toBe(28);
});

test('never measures more than the client can draw', function () {
    $strip = transparentStrip();
    imagefilledrectangle($strip, 40, 0, 49, 99, imagecolorallocatealpha($strip, 200, 10, 10, 0));

    expect((new MeasureStripBodyHeight)->execute($strip))->toBe(MeasureStripBodyHeight::MAX)
        ->and(MeasureStripBodyHeight::MAX)->toBe(SpriteStripBuilder::FOOT_ROW);
});

test('refuses a strip with no ink', function () {
    expect(fn () => (new MeasureStripBodyHeight)->execute(transparentStrip()))
        ->toThrow(BlankStripException::class, 'The idle pose is empty. Check the sheet and build again.');
});

test('refuses a strip whose first frame has no ink even when a later frame does', function () {
    $strip = transparentStrip();
    imagefilledrectangle($strip, 140, 5, 149, 90, imagecolorallocatealpha($strip, 200, 10, 10, 0));

    expect(fn () => (new MeasureStripBodyHeight)->execute($strip))->toThrow(BlankStripException::class);
});

test('measures a freshly built idle strip at the configured height or a little over', function () {
    $idle = app(CustomCharacterBuilder::class)->execute(pngBytes(poseSheetImage(background: 'transparent')))['idle']['image'];

    $height = (new MeasureStripBodyHeight)->execute($idle);

    expect($height)->toBeGreaterThanOrEqual((int) config('token_slayer.custom_character.sprite_height'))
        ->and($height)->toBeLessThanOrEqual((int) config('token_slayer.custom_character.sprite_height') + 2);
});
