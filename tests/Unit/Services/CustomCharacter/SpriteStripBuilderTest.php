<?php

use App\Services\CustomCharacter\Sprite;
use App\Services\CustomCharacter\SpriteStripBuilder;

/**
 * A solid-colour sprite of the given size.
 *
 * @param  int  $width  width in px
 * @param  int  $height  height in px
 * @param  int  $rgb  0xRRGGBB fill
 * @return Sprite
 */
function solidSprite(int $width, int $height, int $rgb): Sprite
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, $rgb);

    return new Sprite($width, $height, $image);
}

/**
 * Sprites for every row, each pose a distinct colour so a strip frame can be traced back to its pose.
 *
 * @return array<string, array<int, Sprite>>
 */
function colouredPoses(): array
{
    return [
        'idle' => [solidSprite(10, 20, 0x100000), solidSprite(10, 20, 0x110000), solidSprite(10, 20, 0x120000), solidSprite(10, 20, 0x130000)],
        'walk' => array_map(fn (int $i) => solidSprite(10, 20, 0x200000 + $i), range(0, 3)),
        'attack' => array_map(fn (int $i) => solidSprite(10, 20, 0x300000 + $i), range(0, 2)),
        'death' => array_map(fn (int $i) => solidSprite(10, 20, 0x400000 + $i), range(0, 2)),
    ];
}

/**
 * The colour of one pixel of one frame of a strip.
 *
 * @param  GdImage  $strip  the strip image
 * @param  int  $frame  frame index
 * @param  int  $x  column inside the 100 px frame
 * @param  int  $y  row inside the frame
 * @return int 0xRRGGBB, or -1 when the pixel is transparent
 */
function stripPixel(GdImage $strip, int $frame, int $x, int $y): int
{
    $argb = imagecolorat($strip, $frame * 100 + $x, $y);

    return (($argb >> 24) & 0x7F) === 0 ? $argb & 0xFFFFFF : -1;
}

test('builds one strip per animation, every frame 100 px square', function () {
    $strips = app(SpriteStripBuilder::class)->build(colouredPoses());

    expect(array_keys($strips))->toBe(['idle', 'walk', 'attack', 'death'])
        ->and(array_map(fn (array $strip) => $strip['frames'], $strips))->toBe(['idle' => 6, 'walk' => 8, 'attack' => 6, 'death' => 3])
        ->and(array_map(fn (array $strip) => $strip['rate'], $strips))->toBe(['idle' => 8, 'walk' => 10, 'attack' => 12, 'death' => 6]);

    foreach ($strips as $strip) {
        expect(imagesx($strip['image']))->toBe($strip['frames'] * 100)
            ->and(imagesy($strip['image']))->toBe(100);
    }
});

test('stands the pose on the roster foot line, centred horizontally', function () {
    $idle = app(SpriteStripBuilder::class)->build(colouredPoses())['idle']['image'];

    // A 10 x 20 sprite: feet on row 58, so it spans rows 39..58 and columns 45..54.
    expect(stripPixel($idle, 0, 50, 58))->not->toBe(-1)
        ->and(stripPixel($idle, 0, 50, 59))->toBe(-1)
        ->and(stripPixel($idle, 0, 50, 39))->not->toBe(-1)
        ->and(stripPixel($idle, 0, 50, 38))->toBe(-1)
        ->and(stripPixel($idle, 0, 45, 50))->not->toBe(-1)
        ->and(stripPixel($idle, 0, 44, 50))->toBe(-1)
        ->and(stripPixel($idle, 0, 54, 50))->not->toBe(-1)
        ->and(stripPixel($idle, 0, 55, 50))->toBe(-1);
});

test('idles on the last idle pose, bobbing one pixel up and back', function () {
    $idle = app(SpriteStripBuilder::class)->build(colouredPoses())['idle']['image'];

    expect(stripPixel($idle, 0, 50, 58))->toBe(0x130000)
        ->and(stripPixel($idle, 1, 50, 58))->toBe(0x130000)
        ->and(stripPixel($idle, 2, 50, 58))->toBe(-1)
        ->and(stripPixel($idle, 2, 50, 57))->toBe(0x130000)
        ->and(stripPixel($idle, 4, 50, 58))->toBe(0x130000);
});

test('holds each walk, attack and death pose in order', function () {
    $strips = app(SpriteStripBuilder::class)->build(colouredPoses());
    $colourOfFrame = fn (string $row, int $frame) => stripPixel($strips[$row]['image'], $frame, 50, 55);

    expect(array_map(fn (int $f) => $colourOfFrame('walk', $f), range(0, 7)))
        ->toBe([0x200000, 0x200000, 0x200001, 0x200001, 0x200002, 0x200002, 0x200003, 0x200003])
        ->and(array_map(fn (int $f) => $colourOfFrame('attack', $f), range(0, 5)))
        ->toBe([0x300000, 0x300000, 0x300001, 0x300001, 0x300002, 0x300002])
        ->and(array_map(fn (int $f) => $colourOfFrame('death', $f), range(0, 2)))
        ->toBe([0x400000, 0x400001, 0x400002]);
});
