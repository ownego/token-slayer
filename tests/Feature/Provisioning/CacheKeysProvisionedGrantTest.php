<?php

use App\Support\CacheKeys;

it('keeps the Pending-badge staleness window independent of the (now removed) cache TTL', function () {
    // A raw incident-reconstruction detail: this constant used to share one
    // value with the grant secret's cache TTL, which no longer exists (the
    // secret lives durably on the grant row instead) — this stays its own
    // number regardless.
    expect(CacheKeys::PROVISIONED_GRANT_PENDING_BADGE_SECONDS)->toBe(86400);
});
