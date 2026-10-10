<?php

use App\Services\CustomCharacter\Sprite;
use App\Services\CustomCharacter\SpriteOutliner;

/**
 * A 4 x 3 sprite whose inner 2 x 1 block (x 1..2, y 1) is opaque red.
 *
 * @return Sprite
 */
function smallSprite(): Sprite
{
    $image = imagecreatetruecolor(4, 3);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 1, 1, 2, 1, imagecolorallocate($image, 200, 30, 30));

    return new Sprite(4, 3, $image);
}

test('grows the sprite by one pixel on every side', function () {
    $outlined = app(SpriteOutliner::class)->outline(smallSprite());

    expect($outlined->width)->toBe(6)
        ->and($outlined->height)->toBe(5);
});

test('draws the outline colour on empty pixels touching the sprite, and keeps the sprite itself', function () {
    config(['token_slayer.custom_character.outline_color' => '#102030']);

    $outlined = app(SpriteOutliner::class)->outline(smallSprite());
    $rgb = fn (int $x, int $y) => imagecolorat($outlined->image, $x, $y) & 0xFFFFFF;

    // The sprite's block moved to x 2..3, y 2; its four side neighbours become outline.
    expect($rgb(2, 2))->toBe(0xC81E1E)
        ->and($rgb(3, 2))->toBe(0xC81E1E)
        ->and($rgb(1, 2))->toBe(0x102030)
        ->and($rgb(4, 2))->toBe(0x102030)
        ->and($rgb(2, 1))->toBe(0x102030)
        ->and($rgb(3, 3))->toBe(0x102030);
});

test('leaves diagonal corners and distant pixels transparent', function () {
    $outlined = app(SpriteOutliner::class)->outline(smallSprite());

    expect($outlined->isOpaque(1, 1))->toBeFalse()
        ->and($outlined->isOpaque(4, 3))->toBeFalse()
        ->and($outlined->isOpaque(0, 0))->toBeFalse();
});
