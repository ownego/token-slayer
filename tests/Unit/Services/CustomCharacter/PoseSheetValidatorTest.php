<?php

use App\Enums\PoseSheetProblem;
use App\Exceptions\InvalidPoseSheetException;
use App\Services\CustomCharacter\PoseBox;
use App\Services\CustomCharacter\PoseSheetValidator;

/**
 * Builds a located-poses map where every pose is a box of the given size,
 * well inside a 1000x1000 sheet.
 *
 * @param  int  $height  pose height in px
 * @return array<string, array<int, PoseBox>>
 */
function evenPoses(int $height = 200): array
{
    $row = fn (int $count, int $top) => array_map(
        fn (int $i) => new PoseBox(50 + $i * 220, $top, 150, $height),
        range(0, $count - 1),
    );

    return [
        'idle' => $row(4, 50),
        'walk' => $row(4, 300),
        'attack' => $row(3, 550),
        'death' => $row(3, 800 - $height + 100),
    ];
}

/**
 * Runs the validator and returns the problem it rejected the poses with.
 *
 * @param  array<string, array<int, PoseBox>>  $poses  located poses
 * @return ?PoseSheetProblem
 */
function validatorRejection(array $poses): ?PoseSheetProblem
{
    try {
        app(PoseSheetValidator::class)->assertUsable($poses, 1000, 1000);
    } catch (InvalidPoseSheetException $exception) {
        return $exception->problem;
    }

    return null;
}

test('accepts evenly sized poses that sit inside the sheet', function () {
    expect(validatorRejection(evenPoses()))->toBeNull();
});

test('rejects a pose that touches a sheet edge, since it was probably cropped', function (string $edge) {
    $poses = evenPoses();

    $poses['walk'][1] = match ($edge) {
        'left' => new PoseBox(0, 300, 150, 200),
        'top' => new PoseBox(270, 0, 150, 200),
        'right' => new PoseBox(850, 300, 150, 200),
        'bottom' => new PoseBox(270, 800, 150, 200),
    };

    expect(validatorRejection($poses))->toBe(PoseSheetProblem::PoseTouchesEdge);
})->with(['left', 'top', 'right', 'bottom']);

test('rejects an idle or walk pose whose height strays too far from the rest', function (string $row) {
    $poses = evenPoses();
    $poses[$row][2] = new PoseBox(490, $poses[$row][2]->top, 150, 320);

    expect(validatorRejection($poses))->toBe(PoseSheetProblem::PoseSizeMismatch);
})->with(['idle', 'walk']);

test('lets attack and death poses differ in height, as a raised sword or a fallen body does', function () {
    $poses = evenPoses();
    $poses['attack'][0] = new PoseBox(50, 520, 150, 330);
    $poses['death'][2] = new PoseBox(490, 800, 150, 90);

    expect(validatorRejection($poses))->toBeNull();
});
