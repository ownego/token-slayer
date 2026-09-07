<?php

use App\Services\Accounts\UsageBuckets;

test('lists every per-model bucket the response happens to carry', function () {
    // The point of reading these out of the stored response instead of typed
    // columns: a model that did not exist when this code was written still
    // shows up, with no migration and no deploy.
    $buckets = UsageBuckets::from([
        'five_hour' => ['utilization' => 12, 'resets_at' => '2026-09-07T06:30:00+00:00'],
        'seven_day' => ['utilization' => 40, 'resets_at' => '2026-09-10T06:30:00+00:00'],
        'seven_day_opus' => ['utilization' => 61, 'resets_at' => null],
        'nimbus_quill' => ['utilization' => 3, 'resets_at' => null],
    ]);

    expect(array_column($buckets, 'key'))->toBe(['seven_day_opus', 'nimbus_quill'])
        ->and(array_column($buckets, 'utilization'))->toBe([61, 3]);
});

test('leaves the two headline buckets out', function () {
    // five_hour and seven_day are the account's own quota, already shown as
    // their own figures. Repeating them in a per-model breakdown would read
    // as two more models.
    $buckets = UsageBuckets::from([
        'five_hour' => ['utilization' => 12],
        'seven_day' => ['utilization' => 40],
    ]);

    expect($buckets)->toBe([]);
});

test('keeps a codename bucket under its codename', function () {
    // Anthropic ships a bucket under a codename before the model is
    // announced. Guessing at a friendlier name would invent a label that
    // matches nothing; showing the codename is the honest answer and needs
    // no code change when the next one appears.
    $buckets = UsageBuckets::from(['tangelo' => ['utilization' => 7]]);

    expect($buckets[0]['key'])->toBe('tangelo');
});

test('ranks the fullest bucket first', function () {
    $buckets = UsageBuckets::from([
        'seven_day_sonnet' => ['utilization' => 5],
        'seven_day_opus' => ['utilization' => 88],
        'cinder_cove' => ['utilization' => 40],
    ]);

    expect(array_column($buckets, 'key'))->toBe(['seven_day_opus', 'cinder_cove', 'seven_day_sonnet']);
});

test('skips entries that carry no utilization figure', function () {
    // `extra_usage` is shaped like a bucket but reports null, and `limits`,
    // `spend` and the flags are not buckets at all. Rendering any of them as
    // a model would be a wrong answer, not a missing one.
    $buckets = UsageBuckets::from([
        'extra_usage' => ['utilization' => null],
        'limits' => [['kind' => 'session', 'percent' => 0]],
        'spend' => ['amount' => 0],
        'member_dashboard_available' => true,
        'seven_day_opus' => ['utilization' => 61],
    ]);

    expect(array_column($buckets, 'key'))->toBe(['seven_day_opus']);
});

test('carries the reset time when the bucket reports one', function () {
    $buckets = UsageBuckets::from([
        'seven_day_opus' => ['utilization' => 61, 'resets_at' => '2026-09-10T06:30:00.123456+00:00'],
    ]);

    expect($buckets[0]['resets_at']?->toDateTimeString())->toBe('2026-09-10 06:30:00');
});

test('returns nothing for a snapshot with no usable body', function (mixed $raw) {
    expect(UsageBuckets::from($raw))->toBe([]);
})->with([
    'empty' => [[]],
    'null' => [null],
    'not an object' => ['garbage'],
]);
