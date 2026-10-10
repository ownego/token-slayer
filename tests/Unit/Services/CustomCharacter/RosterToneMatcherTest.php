<?php

use App\Services\CustomCharacter\RosterToneMatcher;
use App\Services\CustomCharacter\Sprite;

/**
 * A 2 x 1 sprite: a left pixel of the given colour and a transparent right pixel.
 *
 * @param  int  $rgb  0xRRGGBB colour of the left pixel
 * @return Sprite
 */
function twoPixelSprite(int $rgb): Sprite
{
    $image = imagecreatetruecolor(2, 1);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagesetpixel($image, 0, 0, $rgb);

    return new Sprite(2, 1, $image);
}

/**
 * Runs the matcher over one sprite and returns the left pixel's colour.
 *
 * @param  int  $rgb  0xRRGGBB colour of the sprite's left pixel
 * @return int the matched 0xRRGGBB colour
 */
function matchedColour(int $rgb): int
{
    $matched = app(RosterToneMatcher::class)->match(['idle' => [twoPixelSprite($rgb)]]);

    return imagecolorat($matched['idle'][0]->image, 0, 0) & 0xFFFFFF;
}

/**
 * Colour chroma (largest minus smallest channel), a proxy for saturation.
 *
 * @param  int  $rgb  0xRRGGBB colour
 * @return int
 */
function chromaOf(int $rgb): int
{
    $channels = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];

    return max($channels) - min($channels);
}

test('tones a vivid colour down toward the roster look', function () {
    config(['token_slayer.custom_character.tone_saturation' => 0.7, 'token_slayer.custom_character.tone_lightness' => 1.0]);

    expect(chromaOf(matchedColour(0xFFC800)))->toBeLessThan(chromaOf(0xFFC800));
});

test('leaves greys and blacks the same hue-free colour', function (int $grey) {
    config(['token_slayer.custom_character.tone_saturation' => 0.7, 'token_slayer.custom_character.tone_lightness' => 1.0]);

    expect(chromaOf(matchedColour($grey)))->toBe(0);
})->with([
    'black' => [0x000000],
    'grey' => [0x808080],
    'white' => [0xFFFFFF],
]);

test('keeps the hue of the colour it tones down', function () {
    config(['token_slayer.custom_character.tone_saturation' => 0.7, 'token_slayer.custom_character.tone_lightness' => 1.0]);

    $matched = matchedColour(0xFF2000);

    // Still red-dominant, blue and green stay the smaller channels.
    expect(($matched >> 16) & 0xFF)->toBeGreaterThan(($matched >> 8) & 0xFF)
        ->and(($matched >> 8) & 0xFF)->toBeGreaterThanOrEqual($matched & 0xFF);
});

test('leaves a transparent pixel transparent', function () {
    $matched = app(RosterToneMatcher::class)->match(['idle' => [twoPixelSprite(0xFFC800)]]);

    expect($matched['idle'][0]->isOpaque(1, 0))->toBeFalse()
        ->and($matched['idle'][0]->isOpaque(0, 0))->toBeTrue();
});

test('never produces more colours than it was given', function () {
    $image = imagecreatetruecolor(4, 1);
    imagealphablending($image, false);
    imagesavealpha($image, true);

    foreach ([0xFFC800, 0xFFC900, 0x2050A0, 0x2050A0] as $x => $rgb) {
        imagesetpixel($image, $x, 0, $rgb);
    }

    $matched = app(RosterToneMatcher::class)->match(['idle' => [new Sprite(4, 1, $image)]])['idle'][0];
    $colours = [];

    for ($x = 0; $x < 4; $x++) {
        $colours[imagecolorat($matched->image, $x, 0) & 0xFFFFFF] = true;
    }

    expect(count($colours))->toBeLessThanOrEqual(3);
});
