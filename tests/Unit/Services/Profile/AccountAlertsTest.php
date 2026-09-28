<?php

use App\Services\Profile\AccountAlerts;
use Illuminate\Support\Carbon;

test('flags a critical alert when 5h or 7d utilization is at or above 95%', function () {
    $rows = [[
        'account_id' => 1, 'email' => 'team-a@example.com', 'provider' => 'anthropic',
        'util_5h' => 96, 'util_7d' => 40, 'reset_5h_at' => Carbon::now()->addHours(2),
        'reset_7d_at' => null, 'projected_5h' => null, 'projected_7d' => null,
        'codex_windows' => [],
    ]];

    $alerts = app(AccountAlerts::class)->for($rows, ['outdated' => false]);

    expect($alerts)->toHaveCount(1);
    expect($alerts[0]['severity'])->toBe('critical');
    expect($alerts[0]['id'])->toBe('account-1-critical-5h');
    expect($alerts[0]['text'])->toContain('team-a@example.com')->toContain('96');
});

test('suppresses a critical alert once its reset time has already passed (a stale, un-probeable snapshot)', function () {
    $rows = [[
        'account_id' => 1, 'email' => 'team-a@example.com', 'provider' => 'anthropic',
        'util_5h' => 96, 'util_7d' => 40, 'reset_5h_at' => Carbon::now()->subHours(3),
        'reset_7d_at' => null, 'projected_5h' => null, 'projected_7d' => null,
        'codex_windows' => [],
    ]];

    $alerts = app(AccountAlerts::class)->for($rows, ['outdated' => false]);

    expect($alerts)->toBe([]);
});

test('flags a warning when the 7-day projection would exceed 100% before it resets', function () {
    $rows = [[
        'account_id' => 2, 'email' => 'team-b@example.com', 'provider' => 'anthropic',
        'util_5h' => 20, 'util_7d' => 60, 'reset_5h_at' => null,
        'reset_7d_at' => Carbon::parse('2026-09-29 09:00:00'),
        'projected_5h' => null, 'projected_7d' => 112,
        'codex_windows' => [],
    ]];

    $alerts = app(AccountAlerts::class)->for($rows, ['outdated' => false]);

    expect($alerts)->toHaveCount(1);
    expect($alerts[0]['severity'])->toBe('warning');
    expect($alerts[0]['id'])->toBe('account-2-warning-7d');
});

test('never throws and emits no quota alert for an account never probed', function () {
    $rows = [[
        'account_id' => 3, 'email' => 'fresh@example.com', 'provider' => 'anthropic',
        'util_5h' => null, 'util_7d' => null, 'reset_5h_at' => null, 'reset_7d_at' => null,
        'projected_5h' => null, 'projected_7d' => null, 'codex_windows' => [],
    ]];

    $alerts = app(AccountAlerts::class)->for($rows, ['outdated' => false]);

    expect($alerts)->toBe([]);
});

test('reads codex_windows instead of the Claude 5h/7d pair for a Codex account', function () {
    $rows = [[
        'account_id' => 4, 'email' => 'codex-a@example.com', 'provider' => 'codex',
        'util_5h' => null, 'util_7d' => null, 'reset_5h_at' => null, 'reset_7d_at' => null,
        'projected_5h' => null, 'projected_7d' => null,
        'codex_windows' => [['label' => '30-day', 'percent' => 97, 'resets_at' => Carbon::parse('2026-10-01 00:00:00')]],
    ]];

    $alerts = app(AccountAlerts::class)->for($rows, ['outdated' => false]);

    expect($alerts)->toHaveCount(1);
    expect($alerts[0]['severity'])->toBe('critical');
    expect($alerts[0]['id'])->toBe('account-4-critical-codex-30-day');
});

test('adds a CLI-outdated info alert when attribution reports outdated', function () {
    $alerts = app(AccountAlerts::class)->for([], [
        'outdated' => true, 'clientVersion' => '1.0.4', 'latestVersion' => '1.0.5',
    ]);

    expect($alerts)->toHaveCount(1);
    expect($alerts[0])->toBe(['id' => 'cli-outdated', 'severity' => 'info', 'text' => 'Your CLI is 1.0.4. Version 1.0.5 is out.']);
});

test('dismiss ids are unique per account and per alert type, never shared across accounts of the same severity', function () {
    $rows = [
        ['account_id' => 1, 'email' => 'a@example.com', 'provider' => 'anthropic', 'util_5h' => 96, 'util_7d' => 10, 'reset_5h_at' => Carbon::now(), 'reset_7d_at' => null, 'projected_5h' => null, 'projected_7d' => null, 'codex_windows' => []],
        ['account_id' => 2, 'email' => 'b@example.com', 'provider' => 'anthropic', 'util_5h' => 97, 'util_7d' => 10, 'reset_5h_at' => Carbon::now(), 'reset_7d_at' => null, 'projected_5h' => null, 'projected_7d' => null, 'codex_windows' => []],
    ];

    $alerts = app(AccountAlerts::class)->for($rows, ['outdated' => false]);
    $ids = array_column($alerts, 'id');

    expect($ids)->toBe(array_unique($ids));
});
