<?php

use App\Enums\PoseSheetProblem;
use App\Exceptions\InvalidPoseSheetException;
use App\Services\CustomCharacter\PoseSheetKeyer;

test('marks the poses solid and the magenta background empty', function () {
    $sheet = app(PoseSheetKeyer::class)->key(poseSheetImage(background: 'magenta'));

    expect($sheet->isSolid(70, 70))->toBeTrue()
        ->and($sheet->isSolid(5, 5))->toBeFalse();
});

test('marks the poses solid and the transparent background empty', function () {
    $sheet = app(PoseSheetKeyer::class)->key(poseSheetImage(background: 'transparent'));

    expect($sheet->isSolid(70, 70))->toBeTrue()
        ->and($sheet->isSolid(5, 5))->toBeFalse();
});

test('erodes one pixel off a magenta-keyed pose so tinted edge pixels do not survive', function () {
    $sheet = app(PoseSheetKeyer::class)->key(poseSheetImage(background: 'magenta'));

    expect($sheet->isSolid(19, 70))->toBeFalse()
        ->and($sheet->isSolid(20, 70))->toBeTrue();
});

test('keeps a transparent-keyed pose at its exact size', function () {
    $sheet = app(PoseSheetKeyer::class)->key(poseSheetImage(background: 'transparent'));

    expect($sheet->isSolid(19, 70))->toBeTrue()
        ->and($sheet->isSolid(18, 70))->toBeFalse();
});

test('rejects a sheet whose background is neither magenta nor transparent', function () {
    $problem = null;

    try {
        app(PoseSheetKeyer::class)->key(poseSheetImage(background: 'white'));
    } catch (InvalidPoseSheetException $exception) {
        $problem = $exception->problem;
    }

    expect($problem)->toBe(PoseSheetProblem::BackgroundNotSupported);
});
