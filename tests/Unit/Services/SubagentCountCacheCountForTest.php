<?php

use App\Services\SubagentCountCache;

test('countFor reports the busy count from live presence keys and zero idle (idle is a cosmetic client-side notion)', function () {
    $store = [];
    fakeRedis($store);
    $store['subagent:presence:7:agent-a'] = '1';
    $store['subagent:presence:7:agent-b'] = '1';
    $store['subagent:presence:9:agent-c'] = '1'; // a different user — must not count toward user 7

    $counts = app(SubagentCountCache::class)->countFor(7);

    expect($counts)->toBe(['busy' => 2, 'idle' => 0]);
});

test('countFor returns zero for a user with no live presence keys', function () {
    $store = [];
    fakeRedis($store);

    $counts = app(SubagentCountCache::class)->countFor(42);

    expect($counts)->toBe(['busy' => 0, 'idle' => 0]);
});
