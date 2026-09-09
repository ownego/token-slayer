<?php

use App\Services\Accounts\CodexUsageWindows;

test('labels a window by the duration it actually reports', function () {
    // The account this was verified against reports a 30-day cap, not the
    // 5h/7d split Claude uses. Filing it under a 5H label — which is what the
    // prober's fallback used to do — puts a month's usage behind an hour's
    // name, and the reader has no way to know.
    $windows = CodexUsageWindows::from(['rate_limit' => [
        'primary_window' => ['used_percent' => 4, 'limit_window_seconds' => 2592000, 'reset_at' => 1791055976],
        'secondary_window' => null,
    ]]);

    expect($windows)->toHaveCount(1)
        ->and($windows[0]['label'])->toBe('30D')
        ->and($windows[0]['percent'])->toBe(4)
        ->and($windows[0]['resets_at']?->timestamp)->toBe(1791055976);
});

test('names the familiar durations the way the rest of the panel does', function (int $seconds, string $label) {
    $windows = CodexUsageWindows::from(['rate_limit' => [
        'primary_window' => ['used_percent' => 1, 'limit_window_seconds' => $seconds],
    ]]);

    expect($windows[0]['label'])->toBe($label);
})->with([
    'five hours' => [18000, '5H'],
    'seven days' => [604800, '7D'],
    'one day' => [86400, '24H'],
    'thirty days' => [2592000, '30D'],
]);

test('reports both windows a paid account carries, primary first', function () {
    $windows = CodexUsageWindows::from(['rate_limit' => [
        'primary_window' => ['used_percent' => 42, 'limit_window_seconds' => 18000],
        'secondary_window' => ['used_percent' => 17, 'limit_window_seconds' => 604800],
    ]]);

    expect(array_column($windows, 'label'))->toBe(['5H', '7D'])
        ->and(array_column($windows, 'percent'))->toBe([42, 17]);
});

test('keeps a window whose duration is unfamiliar rather than dropping it', function () {
    // An unrecognised duration is still a real limit the account is spending
    // against. Showing the raw hours beats showing nothing, and beats guessing
    // at which named window it must have meant.
    $windows = CodexUsageWindows::from(['rate_limit' => [
        'primary_window' => ['used_percent' => 9, 'limit_window_seconds' => 5400],
    ]]);

    expect($windows[0]['label'])->toBe('1.5H')
        ->and($windows[0]['percent'])->toBe(9);
});

test('skips a window with no percent to report', function () {
    $windows = CodexUsageWindows::from(['rate_limit' => [
        'primary_window' => ['limit_window_seconds' => 18000],
        'secondary_window' => ['used_percent' => 3, 'limit_window_seconds' => 604800],
    ]]);

    expect(array_column($windows, 'label'))->toBe(['7D']);
});

test('returns nothing for a snapshot that is not a Codex usage body', function (mixed $raw) {
    expect(CodexUsageWindows::from($raw))->toBe([]);
})->with([
    'empty' => [[]],
    'null' => [null],
    'a Claude body' => [['five_hour' => ['utilization' => 10]]],
    'rate_limit not an object' => [['rate_limit' => 'nope']],
]);
