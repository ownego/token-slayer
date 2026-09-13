<?php

use App\Services\Accounts\RebalancePlanner;

it('swaps people in both directions rather than piling everyone onto one account', function () {
    // X currently carries the mouse and both mediums (100/100), Y carries
    // only the whale (90/100). The balanced answer is not "move someone to
    // the emptier account" -- it is the whale and the mouse on one, the two
    // mediums on the other, which can only be reached by moving people in
    // BOTH directions.
    $plan = app(RebalancePlanner::class)->plan(
        capacities: [1 => 100.0, 2 => 100.0],
        demands: ['whale' => 90.0, 'mediumA' => 45.0, 'mediumB' => 45.0, 'mouse' => 10.0],
        current: ['whale' => 2, 'mediumA' => 1, 'mediumB' => 1, 'mouse' => 1],
        burstFactors: [],
        safetyMargin: 0.0,
    );

    expect($plan['assignment']['whale'])->toBe(1)
        ->and($plan['assignment']['mouse'])->toBe(1)
        ->and($plan['assignment']['mediumA'])->toBe(2)
        ->and($plan['assignment']['mediumB'])->toBe(2);

    // The diff therefore runs both ways between the same pair of accounts.
    $directions = collect($plan['moves'])->map(fn (array $m): string => "{$m['from']}->{$m['to']}")->unique()->sort()->values()->all();
    expect($directions)->toBe(['1->2', '2->1']);
});

it('leaves an already balanced fleet completely alone', function () {
    $plan = app(RebalancePlanner::class)->plan(
        capacities: [1 => 100.0, 2 => 100.0],
        demands: ['a' => 50.0, 'b' => 50.0],
        current: ['a' => 1, 'b' => 2],
        burstFactors: [],
        safetyMargin: 0.0,
    );

    expect($plan['moves'])->toBe([]);
});

it('reports the fill each account ends up carrying, before and after', function () {
    $plan = app(RebalancePlanner::class)->plan(
        capacities: [1 => 100.0, 2 => 100.0],
        demands: ['whale' => 90.0, 'mouse' => 10.0],
        current: ['whale' => 1, 'mouse' => 1],
        burstFactors: [],
        safetyMargin: 0.0,
    );

    expect($plan['fill_before'][1])->toBe(100.0)
        ->and($plan['fill_before'][2])->toBe(0.0)
        ->and($plan['fill_after'][1])->toBe(90.0)
        ->and($plan['fill_after'][2])->toBe(10.0);
});

it('keeps a safety margin free rather than planning an account to the brim', function () {
    // 20% reserved: each account may be planned to 80 of its 100.
    $plan = app(RebalancePlanner::class)->plan(
        capacities: [1 => 100.0, 2 => 100.0],
        demands: ['a' => 80.0, 'b' => 80.0],
        current: ['a' => 1, 'b' => 1],
        burstFactors: [],
        safetyMargin: 0.2,
    );

    expect($plan['assignment']['a'])->not->toBe($plan['assignment']['b'])
        ->and($plan['unplaced'])->toBe([]);
});

it('pads a bursty person\'s demand, since their peak hour is what trips a window', function () {
    // Flat user and spiky user want the same weekly total, but the spiky one
    // must be planned with more room around them.
    $plan = app(RebalancePlanner::class)->plan(
        capacities: [1 => 1000.0],
        demands: ['flat' => 100.0, 'spiky' => 100.0],
        current: ['flat' => 1, 'spiky' => 1],
        burstFactors: ['flat' => 1.0, 'spiky' => 3.0],
        safetyMargin: 0.0,
    );

    expect($plan['effective_demands']['flat'])->toBe(100.0)
        ->and($plan['effective_demands']['spiky'])->toBe(125.0); // +25%, the cap
});

it('flags someone who does not fit anywhere instead of silently overfilling', function () {
    $plan = app(RebalancePlanner::class)->plan(
        capacities: [1 => 100.0, 2 => 50.0],
        demands: ['giant' => 500.0],
        current: ['giant' => 2],
        burstFactors: [],
        safetyMargin: 0.0,
    );

    // Still placed on the roomiest account -- they have to run somewhere --
    // but reported as not actually fitting.
    expect($plan['assignment']['giant'])->toBe(1)
        ->and($plan['unplaced'])->toHaveKey('giant')
        ->and($plan['unplaced']['giant'])->toBe(400.0); // 500 wanted, 100 available
});

it('breaks a tie on remaining room by putting the person where fewer people already are', function () {
    $plan = app(RebalancePlanner::class)->plan(
        capacities: [1 => 100.0, 2 => 100.0],
        demands: ['first' => 40.0, 'second' => 40.0, 'third' => 10.0],
        current: ['first' => 1, 'second' => 1, 'third' => 1],
        burstFactors: [],
        safetyMargin: 0.0,
    );

    // first -> 1 (60 left), second -> 2 (60 left), third ties on room and
    // goes to whichever holds fewer people; both hold one, so the lower id
    // wins deterministically.
    expect($plan['assignment']['third'])->toBe(1);
});
