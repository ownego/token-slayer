<?php

use App\Enums\PoseSheetProblem;
use App\Exceptions\InvalidPoseSheetException;
use App\Services\CustomCharacter\PoseSheetKeyer;
use App\Services\CustomCharacter\PoseSheetLocator;

/**
 * Keys a synthetic sheet and locates its poses.
 *
 * @param  array<int, int>  $poseCounts  poses per row
 * @param  string  $background  'magenta' or 'transparent'
 * @param  bool  $speckles  scatter stray pixels between the poses
 * @return array<string, array<int, \App\Services\CustomCharacter\PoseBox>>
 */
function locatedPoses(array $poseCounts, string $background = 'transparent', bool $speckles = false): array
{
    $sheet = app(PoseSheetKeyer::class)->key(poseSheetImage($poseCounts, $background, speckles: $speckles));

    return app(PoseSheetLocator::class)->locate($sheet);
}

/**
 * Locates poses and returns the problem the sheet was rejected with.
 *
 * @param  array<int, int>  $poseCounts  poses per row
 * @return ?PoseSheetProblem
 */
function locatorRejection(array $poseCounts): ?PoseSheetProblem
{
    try {
        locatedPoses($poseCounts);
    } catch (InvalidPoseSheetException $exception) {
        return $exception->problem;
    }

    return null;
}

test('names the four rows and finds every pose in each', function () {
    $poses = locatedPoses([4, 4, 3, 3]);

    expect(array_keys($poses))->toBe(['idle', 'walk', 'attack', 'death'])
        ->and(array_map('count', $poses))->toBe(['idle' => 4, 'walk' => 4, 'attack' => 3, 'death' => 3]);
});

test('reports each pose as the bounding box of its ink, left to right', function () {
    $poses = locatedPoses([4, 4, 3, 3]);

    expect($poses['idle'][0]->left)->toBe(19)
        ->and($poses['idle'][0]->top)->toBe(22)
        ->and($poses['idle'][0]->width)->toBe(112)
        ->and($poses['idle'][0]->height)->toBe(105)
        ->and($poses['idle'][1]->left)->toBe(169)
        ->and($poses['walk'][0]->top)->toBe(172);
});

test('finds the same layout on a magenta background', function () {
    $poses = locatedPoses([4, 4, 3, 3], 'magenta');

    expect(array_map('count', $poses))->toBe(['idle' => 4, 'walk' => 4, 'attack' => 3, 'death' => 3]);
});

test('ignores stray single pixels between the poses', function () {
    $poses = locatedPoses([4, 4, 3, 3], speckles: true);

    expect(array_map('count', $poses))->toBe(['idle' => 4, 'walk' => 4, 'attack' => 3, 'death' => 3]);
});

test('accepts the idle row with two to four poses', function (int $idlePoses) {
    expect(locatorRejection([$idlePoses, 4, 3, 3]))->toBeNull();
})->with([
    'two' => [2],
    'three' => [3],
    'four' => [4],
]);

test('rejects a sheet without exactly four rows', function (array $counts) {
    expect(locatorRejection($counts))->toBe(PoseSheetProblem::WrongRowCount);
})->with([
    'three rows' => [[4, 4, 3]],
    'five rows' => [[4, 4, 3, 3, 3]],
]);

test('rejects a row with the wrong number of poses', function (array $counts) {
    expect(locatorRejection($counts))->toBe(PoseSheetProblem::WrongPoseCount);
})->with([
    'idle with one' => [[1, 4, 3, 3]],
    'idle with five' => [[5, 4, 3, 3]],
    'walk with three' => [[4, 3, 3, 3]],
    'attack with four' => [[4, 4, 4, 3]],
    'death with two' => [[4, 4, 3, 2]],
]);
