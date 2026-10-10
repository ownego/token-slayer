<?php

use App\Services\CustomCharacter\PoseSheetKeyer;
use App\Services\CustomCharacter\PoseSheetLocator;
use App\Services\CustomCharacter\StrayMarkRemover;

/**
 * Paints a filled block on the sheet in the pose ink colour.
 *
 * @param  GdImage  $image  the sheet
 * @param  int  $left  block left edge
 * @param  int  $top  block top edge
 * @param  int  $width  block width
 * @param  int  $height  block height
 * @return void
 */
function paintInk(GdImage $image, int $left, int $top, int $width, int $height): void
{
    imagefilledrectangle($image, $left, $top, $left + $width - 1, $top + $height - 1, imagecolorallocate($image, 120, 60, 20));
}

/**
 * Keys and locates the sheet, then runs the remover over it.
 *
 * @param  GdImage  $image  the drawn sheet
 * @return array{0: \App\Services\CustomCharacter\KeyedSheet, 1: array<string, array<int, \App\Services\CustomCharacter\PoseBox>>}
 */
function strayRemoved(GdImage $image): array
{
    $sheet = app(PoseSheetKeyer::class)->key($image);

    return app(StrayMarkRemover::class)->remove($sheet, app(PoseSheetLocator::class)->locate($sheet));
}

test('removes a small detached mark floating above a pose, and shrinks the pose box back', function () {
    $image = poseSheetImage(background: 'transparent');
    // The first pose spans y 22..126; a 12 x 4 spark floats at y 16..19, close enough
    // that the locator folds it into the pose's box, but not touching the pose.
    paintInk($image, 60, 16, 12, 4);

    [$sheet, $poses] = strayRemoved($image);

    expect($sheet->isSolid(65, 17))->toBeFalse()
        ->and($sheet->isSolid(70, 70))->toBeTrue()
        ->and($poses['idle'][0]->top)->toBe(22)
        ->and($poses['idle'][0]->height)->toBe(105);
});

test('keeps a large detached part, such as a sword lying beside a fallen body', function () {
    $image = poseSheetImage(background: 'transparent');
    // 60 x 8 = 480 px against a 11760 px pose is about 4%.
    paintInk($image, 60, 12, 60, 8);

    [$sheet, $poses] = strayRemoved($image);

    expect($sheet->isSolid(80, 15))->toBeTrue()
        ->and($poses['idle'][0]->top)->toBe(12);
});

test('leaves a sheet with nothing stray exactly as it was', function () {
    [$sheet, $poses] = strayRemoved(poseSheetImage(background: 'transparent'));

    expect($poses['idle'][0]->left)->toBe(19)
        ->and($poses['idle'][0]->width)->toBe(112)
        ->and($sheet->isSolid(19, 70))->toBeTrue();
});
