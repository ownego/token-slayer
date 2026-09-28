<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\User;
use App\Services\Profile\AccountRefresh;
use App\Services\ProviderServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

/**
 * A member of a connected account whose latest snapshot reads $util5h.
 *
 * @param  int  $util5h
 * @return array{0: User, 1: Account}
 */
function memberOfProbedAccount(int $util5h): array
{
    $user = User::factory()->create();
    $account = Account::factory()->connected()->create();
    $account->users()->syncWithoutDetaching([$user->id => ['status' => 'tracked']]);
    AccountUsageSnapshot::factory()->for($account)->create(['util_5h' => $util5h, 'created_at' => now()->subMinutes(5)]);

    return [$user, $account];
}

test('a refresh reports how the card\'s headline quota moved', function () {
    [$user, $account] = memberOfProbedAccount(42);
    app()->instance(ProviderServiceFactory::class, fakeProberFactory(function (Account $probed) {
        AccountUsageSnapshot::factory()->for($probed)->create(['util_5h' => 45]);
    }));

    expect(app(AccountRefresh::class)->execute($user, $account->id))
        ->toMatchArray(['status' => 'changed', 'from' => 42, 'to' => 45]);
});

test('a probe that leaves an error reports a failure, not a stale reading as fresh', function () {
    [$user, $account] = memberOfProbedAccount(42);
    app()->instance(ProviderServiceFactory::class, fakeProberFactory(function (Account $probed) {
        $probed->forceFill(['probe_error' => 'token rejected'])->save();
    }));

    expect(app(AccountRefresh::class)->execute($user, $account->id))
        ->toMatchArray(['status' => 'failed', 'error' => 'token rejected']);
});

test('an account refreshes at most once a minute per player, and says how long to wait', function () {
    [$user, $account] = memberOfProbedAccount(42);
    $probes = 0;
    app()->instance(ProviderServiceFactory::class, fakeProberFactory(function () use (&$probes) {
        $probes++;
    }));

    app(AccountRefresh::class)->execute($user, $account->id);
    $second = app(AccountRefresh::class)->execute($user, $account->id);

    expect($probes)->toBe(1)
        ->and($second['status'])->toBe('cooldown')
        ->and($second['seconds'])->toBeGreaterThan(0);
});

test('a player cannot refresh an account they are not a member of', function () {
    [, $account] = memberOfProbedAccount(42);
    $stranger = User::factory()->create();
    $probes = 0;
    app()->instance(ProviderServiceFactory::class, fakeProberFactory(function () use (&$probes) {
        $probes++;
    }));

    expect(app(AccountRefresh::class)->execute($stranger, $account->id)['status'])->toBe('forbidden')
        ->and($probes)->toBe(0);
});

test('an untracked account, hidden from the sheet, cannot be refreshed from it either', function () {
    [$me, $account] = memberOfProbedAccount(42);
    $me->accounts()->updateExistingPivot($account->id, ['status' => MembershipStatus::Untracked->value]);
    $probes = 0;
    app()->instance(ProviderServiceFactory::class, fakeProberFactory(function () use (&$probes) {
        $probes++;
    }));

    expect(app(AccountRefresh::class)->execute($me, $account->id)['status'])->toBe('forbidden')
        ->and($probes)->toBe(0);
});
