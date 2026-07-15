<?php

use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

it('exposes the migrated static key strings', function () {
    expect(CacheKeys::DAMAGE_TOTALS)->toBe('damage-totals:global');
    expect(CacheKeys::ACCOUNTS_EMAIL_MAP)->toBe('accounts:email-map');
    expect(CacheKeys::ACCOUNTS_ORG_MAP)->toBe('accounts:org-map');
});

it('builds per-account membership keys', function () {
    expect(CacheKeys::trackedMembers(7))->toBe('account:7:tracked-members');
    expect(CacheKeys::untrackedContributors(7))->toBe('account:7:untracked-contributors');
});

it('forgets both membership keys for an account', function () {
    Cache::put(CacheKeys::trackedMembers(7), ['x'], 60);
    Cache::put(CacheKeys::untrackedContributors(7), ['y'], 60);

    CacheKeys::forgetAccountMembership(7);

    expect(Cache::has(CacheKeys::trackedMembers(7)))->toBeFalse();
    expect(Cache::has(CacheKeys::untrackedContributors(7)))->toBeFalse();
});

it('forgets the account resolver maps', function () {
    Cache::put(CacheKeys::ACCOUNTS_EMAIL_MAP, ['a' => 1], 60);
    Cache::put(CacheKeys::ACCOUNTS_ORG_MAP, ['b' => 1], 60);

    CacheKeys::forgetAccountMaps();

    expect(Cache::has(CacheKeys::ACCOUNTS_EMAIL_MAP))->toBeFalse();
    expect(Cache::has(CacheKeys::ACCOUNTS_ORG_MAP))->toBeFalse();
});
