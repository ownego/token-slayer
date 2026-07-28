<?php

use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

it('builds the per-grant secret key and forgets it', function () {
    expect(CacheKeys::provisionedGrant(42))->toBe('provisioned:grant:42')
        ->and(CacheKeys::PROVISIONED_GRANT_TTL_SECONDS)->toBe(86400);

    Cache::put(CacheKeys::provisionedGrant(42), 'secret', 60);
    CacheKeys::forgetProvisionedGrant(42);

    expect(Cache::get(CacheKeys::provisionedGrant(42)))->toBeNull();
});
