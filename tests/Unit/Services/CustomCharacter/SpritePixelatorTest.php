<?php

use App\Services\CustomCharacter\PoseSheetKeyer;
use App\Services\CustomCharacter\PoseSheetLocator;
use App\Services\CustomCharacter\Sprite;
use App\Services\CustomCharacter\SpritePixelator;

/**
 * Keys, locates and pixelates a synthetic sheet.
 *
 * @param  GdImage  $image  the drawn sheet
 * @return array<string, array<int, Sprite>>
 */
function pixelatedFrom(GdImage $image): array
{
    $sheet = app(PoseSheetKeyer::class)->key($image);

    return app(SpritePixelator::class)->pixelate($sheet, app(PoseSheetLocator::class)->locate($sheet));
}

test('shrinks every pose by one shared factor so the reference height lands on the sprite height', function () {
    config(['token_slayer.custom_character.sprite_height' => 28]);

    $sprites = pixelatedFrom(poseSheetImage(background: 'transparent'));

    // Each synthetic pose is 112 x 105 px; 105 -> 28 is a factor of 0.2667.
    expect($sprites['idle'][0]->height)->toBe(28)
        ->and($sprites['idle'][0]->width)->toBe(30)
        ->and($sprites['death'][2]->height)->toBe(28);
});

test('draws only the pose colour, with no magenta from the background bleeding into the edge', function () {
    $sprites = pixelatedFrom(poseSheetImage(background: 'magenta'));
    $sprite = $sprites['walk'][1];

    for ($y = 0; $y < $sprite->height; $y++) {
        for ($x = 0; $x < $sprite->width; $x++) {
            if ($sprite->isOpaque($x, $y)) {
                $argb = imagecolorat($sprite->image, $x, $y);
                expect([($argb >> 16) & 0xFF, ($argb >> 8) & 0xFF, $argb & 0xFF])->toBe([120, 60, 20]);
            }
        }
    }
});

test('leaves transparent the pixels of the pose that the ink mask excludes', function () {
    $image = poseSheetImage(background: 'transparent');
    // Cut a 60 x 60 hole out of the middle of the first pose (x 19..130, y 22..126).
    imagefilledrectangle($image, 45, 44, 104, 103, imagecolorallocatealpha($image, 0, 0, 0, 127));

    $sprite = pixelatedFrom($image)['idle'][0];

    expect($sprite->isOpaque(intdiv($sprite->width, 2), intdiv($sprite->height, 2)))->toBeFalse()
        ->and($sprite->isOpaque(0, 0))->toBeTrue();
});

test('reduces every pose to one shared palette of at most the configured colours', function () {
    config(['token_slayer.custom_character.palette_colors' => 8]);

    $image = poseSheetImage(background: 'transparent');
    mt_srand(7);

    for ($y = 22; $y < 127; $y++) {
        for ($x = 19; $x < 131; $x++) {
            imagesetpixel($image, $x, $y, imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
    }

    $colours = [];

    foreach (pixelatedFrom($image) as $row) {
        foreach ($row as $sprite) {
            for ($y = 0; $y < $sprite->height; $y++) {
                for ($x = 0; $x < $sprite->width; $x++) {
                    if ($sprite->isOpaque($x, $y)) {
                        $colours[imagecolorat($sprite->image, $x, $y) & 0xFFFFFF] = true;
                    }
                }
            }
        }
    }

    expect(count($colours))->toBeLessThanOrEqual(8);
});
