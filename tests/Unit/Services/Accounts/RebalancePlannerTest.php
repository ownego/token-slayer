<?php

use App\Services\Accounts\RebalancePlanner;

it('finds the swap that no single move could achieve', function () {
    // X carries 60 and 30, Y carries 50 and 10. Moving anyone one way makes
    // things worse or changes nothing -- send the 30 across and X still
    // peaks at 90 -- but exchanging the 30 for the 10 drops the fullest
    // account from 90 to 80. A planner that only ever pushes people in one
    // direction cannot see that.
    $plan = app(RebalancePlanner::class)->plan(
        capacities: [1 => 100.0, 2 => 100.0],
        demands: ['heavy' => 60.0, 'swapOut' => 30.0, 'mid' => 50.0, 'swapIn' => 10.0],
        current: ['heavy' => 1, 'swapOut' => 1, 'mid' => 2, 'swapIn' => 2],
        burstFactors: [],
        safetyMargin: 0.0,
    );

    expect($plan['assignment']['swapOut'])->toBe(2)
        ->and($plan['assignment']['swapIn'])->toBe(1)
        ->and($plan['assignment']['heavy'])->toBe(1)
        ->and($plan['assignment']['mid'])->toBe(2);

    $directions = collect($plan['moves'])->map(fn (array $m): string => "{$m['from']}->{$m['to']}")->unique()->sort()->values()->all();
    expect($directions)->toBe(['1->2', '2->1']);

    // The two halves of a swap point at each other: applying one alone
    // leaves the fleet worse off than doing nothing.
    expect(collect($plan['moves'])->pluck('swap_with')->filter()->count())->toBe(2);
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

it('does not churn the fleet for a gain too small to be worth the disruption', function () {
    // Every move costs somebody a re-authentication. A 50.5/49.5 split is
    // not a problem worth solving, and a planner that repacks from scratch
    // would still hand back a list of moves for it.
    $plan = app(RebalancePlanner::class)->plan(
        capacities: [1 => 100.0, 2 => 100.0],
        demands: ['a' => 26.0, 'b' => 24.5, 'c' => 25.0, 'd' => 24.5],
        current: ['a' => 1, 'b' => 1, 'c' => 2, 'd' => 2],
        burstFactors: [],
        safetyMargin: 0.0,
    );

    expect($plan['moves'])->toBe([]);
});

it('stops after a handful of moves rather than reshuffling everyone', function () {
    // Twelve people all piled onto one account. There is a perfect packing,
    // but an admin cannot execute twelve re-authentications off the back of
    // one page; the plan has to be a batch someone can actually carry out,
    // and the next run picks up where this one stopped.
    $demands = [];
    $current = [];
    foreach (range(1, 12) as $index) {
        $demands["u{$index}"] = 40.0;
        $current["u{$index}"] = 1;
    }

    $plan = app(RebalancePlanner::class)->plan(
        capacities: [1 => 100.0, 2 => 100.0, 3 => 100.0],
        demands: $demands,
        current: $current,
        burstFactors: [],
        safetyMargin: 0.0,
    );

    expect(count($plan['moves']))->toBeLessThanOrEqual(10)
        ->and(count($plan['moves']))->toBeGreaterThan(0);
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
    // 20% reserved: each account may be planned to 80 of its 100, so two
    // 80s cannot share one account however much raw capacity suggests.
    $plan = app(RebalancePlanner::class)->plan(
        capacities: [1 => 100.0, 2 => 100.0],
        demands: ['a' => 80.0, 'b' => 80.0],
        current: ['a' => 1, 'b' => 1],
        burstFactors: [],
        safetyMargin: 0.2,
    );

    expect($plan['assignment']['a'])->not->toBe($plan['assignment']['b'])
        ->and($plan['overflow'])->toBe([]);
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

it('reports what an account is carrying beyond its usable capacity', function () {
    $plan = app(RebalancePlanner::class)->plan(
        capacities: [1 => 100.0, 2 => 50.0],
        demands: ['giant' => 500.0],
        current: ['giant' => 2],
        burstFactors: [],
        safetyMargin: 0.0,
    );

    // Moved to the roomier account -- they have to run somewhere -- and the
    // shortfall reported against that account rather than silently absorbed.
    expect($plan['assignment']['giant'])->toBe(1)
        ->and($plan['overflow'][1])->toBe(400.0)
        ->and($plan['overflow'])->not->toHaveKey(2);
});

it('brings an account carrying too many people back down to the target', function () {
    // Seven on one account and one on the other, with the weekly load
    // already level so nothing about tokens alone would move anybody. A
    // crowded account is its own risk: those seven can all be working at
    // once, and a 5-hour window is tripped by simultaneity, not by a weekly
    // total.
    $demands = [];
    $current = [];
    foreach (range(1, 7) as $index) {
        $demands["crowd{$index}"] = 50.0;
        $current["crowd{$index}"] = 1;
    }
    $demands['alone'] = 350.0;
    $current['alone'] = 2;

    $plan = app(RebalancePlanner::class)->plan(
        capacities: [1 => 1000.0, 2 => 1000.0],
        demands: $demands,
        current: $current,
        burstFactors: [],
        safetyMargin: 0.0,
        maxMembers: 5,
    );

    $members = array_count_values($plan['assignment']);
    expect(max($members))->toBeLessThanOrEqual(5);
});

it('never adds anyone to an account already holding the target', function () {
    // Account 1 is full at five people and account 2 has room, so the only
    // direction anybody may travel is away from 1 — even though piling more
    // onto it would level the tokens nicely.
    $demands = [];
    $current = [];
    foreach (range(1, 5) as $index) {
        $demands["full{$index}"] = 10.0;
        $current["full{$index}"] = 1;
    }
    $demands['heavy'] = 900.0;
    $current['heavy'] = 2;

    $plan = app(RebalancePlanner::class)->plan(
        capacities: [1 => 1000.0, 2 => 1000.0],
        demands: $demands,
        current: $current,
        burstFactors: [],
        safetyMargin: 0.0,
        maxMembers: 5,
    );

    $members = array_count_values($plan['assignment']);
    expect($members[1] ?? 0)->toBeLessThanOrEqual(5);
});

it('raises the target rather than refusing to place people it cannot fit', function () {
    // Twelve people, two accounts, a target of five: five apiece leaves two
    // with nowhere to go. The target gives way, because a plan that seats
    // nobody is worse than a crowded one.
    $demands = [];
    $current = [];
    foreach (range(1, 12) as $index) {
        $demands["u{$index}"] = 50.0;
        $current["u{$index}"] = 1;
    }

    $plan = app(RebalancePlanner::class)->plan(
        capacities: [1 => 1000.0, 2 => 1000.0],
        demands: $demands,
        current: $current,
        burstFactors: [],
        safetyMargin: 0.0,
        maxMembers: 5,
    );

    expect($plan['assignment'])->toHaveCount(12)
        ->and(max(array_count_values($plan['assignment'])))->toBeLessThanOrEqual(6);
});

it('keeps balancing load by swapping once every account is at the target', function () {
    // Both accounts full at five, so nobody may simply move — but a swap
    // changes no headcount at all, which is what keeps the tokens balanceable
    // after the seats are settled.
    $demands = ['a1' => 200.0, 'a2' => 100.0, 'a3' => 100.0, 'a4' => 100.0, 'a5' => 100.0];
    $current = ['a1' => 1, 'a2' => 1, 'a3' => 1, 'a4' => 1, 'a5' => 1];
    foreach (range(1, 5) as $index) {
        $demands["b{$index}"] = 60.0;
        $current["b{$index}"] = 2;
    }

    $plan = app(RebalancePlanner::class)->plan(
        capacities: [1 => 1000.0, 2 => 1000.0],
        demands: $demands,
        current: $current,
        burstFactors: [],
        safetyMargin: 0.0,
        maxMembers: 5,
    );

    $members = array_count_values($plan['assignment']);
    expect($members[1])->toBe(5)
        ->and($members[2])->toBe(5)
        ->and($plan['moves'])->not->toBe([]);
});
