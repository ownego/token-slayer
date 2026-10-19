<?php

use App\Enums\PoseSheetProblem;

it('gives a concrete repair hint for every rejected pose sheet problem', function () {
    foreach (PoseSheetProblem::cases() as $problem) {
        expect($problem->hint())->not->toBeEmpty();
    }

    expect(PoseSheetProblem::WrongRowCount->hint())->toContain('gap');
    expect(PoseSheetProblem::BackgroundNotSupported->hint())->toContain('#FF00FF');
    // poses that touch (a spell circle running into the next pose) merge into one: the hint says so
    expect(PoseSheetProblem::WrongPoseCount->hint())->toContain('touch')->toContain('spell effects');
});
