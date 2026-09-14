<?php

use App\Services\Accounts\QuotaCostSolver;

it('tells two people apart from windows they always shared', function () {
    // Nobody here ever used the account alone. What separates them is that
    // their shares vary: when the dial moves a lot on few of A's tokens and
    // little on many of B's, the difference has only one explanation.
    // A costs 100 tokens per utilisation point, B costs 300.
    $windows = [
        ['delta' => 40.0, 'tokens' => ['a' => 3_000.0, 'b' => 3_000.0]],
        ['delta' => 70.0, 'tokens' => ['a' => 6_000.0, 'b' => 3_000.0]],
        ['delta' => 40.0, 'tokens' => ['a' => 1_000.0, 'b' => 9_000.0]],
        ['delta' => 40.0, 'tokens' => ['a' => 2_000.0, 'b' => 6_000.0]],
        ['delta' => 55.0, 'tokens' => ['a' => 5_000.0, 'b' => 1_500.0]],
        ['delta' => 25.0, 'tokens' => ['a' => 1_000.0, 'b' => 4_500.0]],
    ];

    $costs = app(QuotaCostSolver::class)->solve($windows);

    expect(round($costs['a'], -1))->toBe(100.0)
        ->and(round($costs['b'], -1))->toBe(300.0);
});

it('recovers a third person hiding inside a crowd', function () {
    // The case described: at one moment three people are active, at another
    // only one of them is, and the dial still moves. That second window is
    // what prices them, and the solver uses both.
    // a costs 50 tokens per point, b costs 100, c costs 200.
    $windows = [
        ['delta' => 70.0, 'tokens' => ['a' => 2_000.0, 'b' => 2_000.0, 'c' => 2_000.0]],
        ['delta' => 20.0, 'tokens' => ['b' => 2_000.0]],
        ['delta' => 85.0, 'tokens' => ['a' => 4_000.0, 'c' => 1_000.0]],
        ['delta' => 45.0, 'tokens' => ['a' => 1_000.0, 'b' => 1_000.0, 'c' => 3_000.0]],
        ['delta' => 15.0, 'tokens' => ['c' => 3_000.0]],
        ['delta' => 70.0, 'tokens' => ['a' => 3_000.0, 'b' => 1_000.0]],
    ];

    $costs = app(QuotaCostSolver::class)->solve($windows);

    expect(round($costs['a'], -1))->toBe(50.0)
        ->and(round($costs['b'], -1))->toBe(100.0)
        ->and(round($costs['c'], -1))->toBe(200.0);
});

it('refuses to split two people who are never apart and never in different proportions', function () {
    // Always together, always half and half: no arrangement of the numbers
    // can say which of them the utilisation belonged to. Returning a figure
    // here would be inventing one.
    $windows = [
        ['delta' => 40.0, 'tokens' => ['a' => 2_000.0, 'b' => 2_000.0]],
        ['delta' => 60.0, 'tokens' => ['a' => 3_000.0, 'b' => 3_000.0]],
        ['delta' => 20.0, 'tokens' => ['a' => 1_000.0, 'b' => 1_000.0]],
        ['delta' => 80.0, 'tokens' => ['a' => 4_000.0, 'b' => 4_000.0]],
    ];

    expect(app(QuotaCostSolver::class)->solve($windows))->toBe([]);
});

it('ignores windows in which the dial never moved', function () {
    $windows = [
        ['delta' => 0.0, 'tokens' => ['a' => 5_000.0]],
        ['delta' => 40.0, 'tokens' => ['a' => 4_000.0, 'b' => 0.0]],
        ['delta' => 20.0, 'tokens' => ['a' => 2_000.0]],
        ['delta' => 10.0, 'tokens' => ['a' => 1_000.0]],
    ];

    $costs = app(QuotaCostSolver::class)->solve($windows);

    expect(round($costs['a'], -1))->toBe(100.0);
});

it('returns nothing at all when there is less evidence than there are people', function () {
    // Two unknowns and one equation. Any answer fits, which means none of
    // them is an answer.
    expect(app(QuotaCostSolver::class)->solve([
        ['delta' => 40.0, 'tokens' => ['a' => 2_000.0, 'b' => 1_000.0]],
    ]))->toBe([]);
});
