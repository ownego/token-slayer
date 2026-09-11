<?php

use App\Services\Analytics\UsageFilters;

test('a range of 48 hours or less buckets hourly', function () {
    $f = new UsageFilters(now()->subHours(24), now(), null, null, null);

    expect($f->bucket)->toBe('hour');
});

test('a blank account, provider or user filter means no filter (show all)', function () {
    $f = UsageFilters::fromPageFilters([
        'range' => '7d',
        'account_id' => '',
        'provider' => '',
        'user_id' => '',
    ]);

    expect($f->accountId)->toBeNull()
        ->and($f->provider)->toBeNull()
        ->and($f->userId)->toBeNull();
});

test('a range longer than 48 hours buckets daily', function () {
    $f = new UsageFilters(now()->subDays(7), now(), null, null, null);

    expect($f->bucket)->toBe('day');
});

test('it builds from the 24h preset with a null account, provider and user', function () {
    $f = UsageFilters::fromPageFilters(['range' => '24h']);

    expect($f->bucket)->toBe('hour')
        ->and($f->accountId)->toBeNull()
        ->and($f->provider)->toBeNull()
        ->and($f->userId)->toBeNull()
        ->and($f->from->greaterThan(now()->subHours(25)))->toBeTrue();
});

test('it carries account, provider and user selections through', function () {
    $f = UsageFilters::fromPageFilters([
        'range' => '7d',
        'account_id' => 5,
        'provider' => 'codex',
        'user_id' => 9,
    ]);

    expect($f->accountId)->toBe(5)
        ->and($f->provider)->toBe('codex')
        ->and($f->userId)->toBe(9)
        ->and($f->bucket)->toBe('day');
});

test('it clamps an over-long custom range to ninety days', function () {
    $f = UsageFilters::fromPageFilters([
        'range' => 'custom',
        'from' => now()->subDays(365)->toDateString(),
        'to' => now()->toDateString(),
    ]);

    expect($f->from->greaterThanOrEqualTo(now()->subDays(91)))->toBeTrue();
});

test('a range of exactly 48 hours buckets hourly but just over buckets daily', function () {
    $from = now();
    expect((new UsageFilters($from, $from->copy()->addHours(48), null, null, null))->bucket)->toBe('hour')
        ->and((new UsageFilters($from, $from->copy()->addHours(48)->addSecond(), null, null, null))->bucket)->toBe('day');
});

test('defaults the token mode to output when the filter form omits it', function () {
    $f = UsageFilters::fromPageFilters(['range' => '7d']);

    expect($f->tokenMode)->toBe('output')
        ->and($f->tokenColumnExpression())->toBe('events.tokens');
});

test('carries the total token mode through and builds the sum-of-columns expression', function () {
    $f = UsageFilters::fromPageFilters(['range' => '7d', 'token_mode' => 'total']);

    expect($f->tokenMode)->toBe('total')
        ->and($f->tokenColumnExpression())->toBe(
            '(events.tokens + events.input_tokens + events.cache_creation_input_tokens + events.cache_read_input_tokens)'
        );
});

test('any token mode value other than total falls back to output', function () {
    // A stray/unexpected value on the wire must never build an ad-hoc SQL
    // fragment -- fall back to the safe, always-correct default instead.
    $f = UsageFilters::fromPageFilters(['range' => '7d', 'token_mode' => 'garbage']);

    expect($f->tokenMode)->toBe('output');
});
