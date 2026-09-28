<?php

use App\Services\Profile\AccountCardSummaries;
use Illuminate\Support\Carbon;

/**
 * An AccountQuotaCards row with only the keys the summaries read.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function quotaRow(array $overrides = []): array
{
    return [
        'account_id' => 1,
        'util_5h' => null, 'reset_5h_at' => null, 'projected_5h' => null,
        'util_7d' => null, 'reset_7d_at' => null, 'projected_7d' => null,
        'codex_windows' => [],
        ...$overrides,
    ];
}

beforeEach(fn () => Carbon::setTestNow('2026-09-27 12:00:00'));

test('a Claude account reports its 5-hour and 7-day windows as meters', function () {
    $card = (new AccountCardSummaries)->present([quotaRow(['util_5h' => 42, 'util_7d' => 18])])[0];

    expect(array_column($card['windows'], 'name'))->toBe(['5-hour', '7-day'])
        ->and($card['worst'])->toBe('good')
        ->and($card['summary'])->toBe('Every quota has room');
});

test('a Codex account reports its own windows instead of the Claude pair', function () {
    $card = (new AccountCardSummaries)->present([quotaRow(['codex_windows' => [['label' => '30-day', 'percent' => 12, 'resets_at' => null]]])])[0];

    expect(array_column($card['windows'], 'name'))->toBe(['30-day']);
});

test('the fullest window names the card\'s state and sentence', function () {
    $card = (new AccountCardSummaries)->present([quotaRow(['util_5h' => 96, 'reset_5h_at' => now()->addMinutes(38), 'util_7d' => 18])])[0];

    expect($card['worst'])->toBe('critical')
        ->and($card['summary'])->toBe('5-hour almost out, refills in 38m');
});

test('a window projected past its cap warns before it is close', function () {
    $card = (new AccountCardSummaries)->present([quotaRow(['util_7d' => 60, 'projected_7d' => 124, 'reset_7d_at' => now()->addDays(2)])])[0];

    expect($card['worst'])->toBe('warning')
        ->and($card['summary'])->toBe('7-day runs out before it refills at this pace');
});

test('a reset already in the past reads as stale, never as a refill countdown', function () {
    $card = (new AccountCardSummaries)->present([quotaRow(['util_5h' => 96, 'reset_5h_at' => now()->subHours(3)])])[0];

    expect($card['summary'])->toBe("5-hour hasn't refreshed since it last reset");
});

test('an account never probed has no meters and no state', function () {
    $card = (new AccountCardSummaries)->present([quotaRow()])[0];

    expect($card['windows'])->toBe([])
        ->and($card['worst'])->toBeNull()
        ->and($card['summary'])->toBeNull();
});

test('"most room" marks the emptiest account only when there is a choice', function () {
    $service = new AccountCardSummaries;

    $two = $service->present([quotaRow(['account_id' => 1, 'util_5h' => 80]), quotaRow(['account_id' => 2, 'util_5h' => 20])]);
    $one = $service->present([quotaRow(['util_5h' => 20])]);

    expect(array_column($two, 'roomiest'))->toBe([false, true])
        ->and($one[0]['roomiest'])->toBeFalse();
});
