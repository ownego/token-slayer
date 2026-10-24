<?php

use App\Enums\IncompleteUsageReason;
use App\Models\User;
use App\Services\Events\IncompleteUsageFlag;
use App\Services\Pricing\PricedUsage;

/**
 * A turn as the given provider reports it, complete or not.
 *
 * @param  bool  $complete  whether model and input/cache tokens are present
 * @param  string  $provider  the `events.provider` value
 * @return PricedUsage
 */
function flagUsage(bool $complete, string $provider = 'claude-code'): PricedUsage
{
    return $complete
        ? new PricedUsage($provider, 'claude-sonnet-5-5', 10, 100, 0, 0, false)
        : new PricedUsage($provider, null, 10, 0, 0, 0, false);
}

it('says which user columns a turn changes', function (int $counter, ?PricedUsage $usage, ?int $accountId, ?string $hookVersion, array $expected) {
    $user = (new User)->forceFill(['incomplete_events' => $counter]);

    expect((new IncompleteUsageFlag)->attributesFor($user, $usage, $accountId, $hookVersion))->toBe($expected);
})->with([
    'non-Stop event untouched' => fn () => [0, null, 5, '7', []],
    'personal event untouched' => fn () => [0, flagUsage(false), null, '7', []],
    'claude-ai untouched' => fn () => [0, flagUsage(false, 'claude-ai'), 5, '7', []],
    'old hook v5' => fn () => [0, flagUsage(false), 5, '5', ['incomplete_events' => 1, 'incomplete_reason' => IncompleteUsageReason::OldHook]],
    'no hook version' => fn () => [2, flagUsage(false), 5, null, ['incomplete_events' => 3, 'incomplete_reason' => IncompleteUsageReason::OldHook]],
    'v7 incomplete is modified' => fn () => [0, flagUsage(false), 5, '7', ['incomplete_events' => 1, 'incomplete_reason' => IncompleteUsageReason::ModifiedHook]],
    'v6 incomplete is modified' => fn () => [0, flagUsage(false), 5, '6', ['incomplete_events' => 1, 'incomplete_reason' => IncompleteUsageReason::ModifiedHook]],
    'complete resets' => fn () => [4, flagUsage(true), 5, '7', ['incomplete_events' => 0, 'incomplete_reason' => null]],
    'complete with zero counter writes nothing' => fn () => [0, flagUsage(true), 5, '7', []],
]);

it('labels each reason for the account card tooltip', function () {
    expect(IncompleteUsageReason::OldHook->getLabel())->toBe('Old hook, reinstall needed')
        ->and(IncompleteUsageReason::ModifiedHook->getLabel())->toBe('Modified hook or unknown client');
});
