<?php

use App\Models\Event;
use App\Models\User;
use App\Services\Analytics\ActivityHeatmapQuery;
use App\Services\Analytics\TokensByModelQuery;
use App\Services\Analytics\TokenVolumeQuery;
use App\Services\Analytics\TopAccountsQuery;
use App\Services\Analytics\TopUsersQuery;
use App\Services\Analytics\UsageFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The output/total token-mode toggle applies uniformly across every
 * dashboard widget that sums `events.tokens`, so a switch flips the same
 * numbers everywhere at once rather than leaving some charts on output and
 * others silently still on total.
 */
test('every analytics query sums only output tokens by default, matching pre-filter behavior', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create([
        'model' => 'claude-sonnet-5',
        'provider' => 'claude-code',
        'tokens' => 335,
        'input_tokens' => 2,
        'cache_creation_input_tokens' => 791,
        'cache_read_input_tokens' => 140_177,
    ]);

    $filters = UsageFilters::fromPageFilters(['range' => 'all']);

    expect((new TopUsersQuery)->get($filters, 10)[0]['tokens'])->toBe(335)
        ->and((new TopAccountsQuery)->get($filters, 10)[0]['tokens'])->toBe(335)
        ->and((new TokenVolumeQuery)->get($filters)[0]['tokens'])->toBe(335)
        ->and((new TokensByModelQuery)->get($filters)[0]['tokens'])->toBe(335)
        ->and(collect((new ActivityHeatmapQuery)->get($filters))->sum('tokens'))->toBe(335);
});

test('every analytics query sums output plus input and cache tokens in total mode', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create([
        'model' => 'claude-sonnet-5',
        'provider' => 'claude-code',
        'tokens' => 335,
        'input_tokens' => 2,
        'cache_creation_input_tokens' => 791,
        'cache_read_input_tokens' => 140_177,
    ]);
    $expectedTotal = 335 + 2 + 791 + 140_177;

    $filters = UsageFilters::fromPageFilters(['range' => 'all', 'token_mode' => 'total']);

    expect((new TopUsersQuery)->get($filters, 10)[0]['tokens'])->toBe($expectedTotal)
        ->and((new TopAccountsQuery)->get($filters, 10)[0]['tokens'])->toBe($expectedTotal)
        ->and((new TokenVolumeQuery)->get($filters)[0]['tokens'])->toBe($expectedTotal)
        ->and((new TokensByModelQuery)->get($filters)[0]['tokens'])->toBe($expectedTotal)
        ->and(collect((new ActivityHeatmapQuery)->get($filters))->sum('tokens'))->toBe($expectedTotal);
});

test('the unknown-model share also honors total mode, not just the per-model breakdown', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create([
        'model' => null,
        'tokens' => 100,
        'input_tokens' => 0,
        'cache_creation_input_tokens' => 0,
        'cache_read_input_tokens' => 900,
    ]);

    $output = (new TokensByModelQuery)->unknownShare(UsageFilters::fromPageFilters(['range' => 'all']));
    $total = (new TokensByModelQuery)->unknownShare(UsageFilters::fromPageFilters(['range' => 'all', 'token_mode' => 'total']));

    // Same single all-unknown row either way -- 100% in both modes -- but the
    // total-mode call must not error out building `SUM(events.tokens +
    // events.input_tokens + ...)` through the query builder's own `sum()`.
    expect($output)->toBe(100.0)
        ->and($total)->toBe(100.0);
});
