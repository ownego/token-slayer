<?php

use App\Services\Accounts\ModelQuotaLimits;

test('names a model-scoped limit by the display name the API supplies', function () {
    // The API already knows what to call it. Reading the top-level buckets
    // instead gave an unnamed placeholder (`nimbus_quill`, utilization 0) and
    // no way to tell an admin which model it belonged to.
    $limits = ModelQuotaLimits::from(['limits' => [
        ['kind' => 'session', 'percent' => 43, 'severity' => 'normal', 'scope' => null],
        ['kind' => 'weekly_all', 'percent' => 28, 'severity' => 'normal', 'scope' => null],
        ['kind' => 'weekly_scoped', 'percent' => 28, 'severity' => 'normal',
            'resets_at' => '2026-09-12T23:59:59.871734+00:00',
            'scope' => ['model' => ['id' => null, 'display_name' => 'Fable'], 'surface' => null]],
    ]]);

    expect($limits)->toHaveCount(1)
        ->and($limits[0]['model'])->toBe('Fable')
        ->and($limits[0]['percent'])->toBe(28)
        ->and($limits[0]['severity'])->toBe('normal')
        ->and($limits[0]['resets_at']?->toDateTimeString())->toBe('2026-09-12 23:59:59');
});

test('leaves out the account-wide limits', function () {
    // session and weekly_all are the 5h and 7d figures, already shown as their
    // own gauges. Repeating them would read as two more models.
    $limits = ModelQuotaLimits::from(['limits' => [
        ['kind' => 'session', 'percent' => 43, 'scope' => null],
        ['kind' => 'weekly_all', 'percent' => 28, 'scope' => null],
    ]]);

    expect($limits)->toBe([]);
});

test('skips a scoped limit that names no model', function () {
    // A scope can carry a surface instead of a model. Rendering it as a model
    // row would put a label on something that is not one.
    $limits = ModelQuotaLimits::from(['limits' => [
        ['kind' => 'weekly_scoped', 'percent' => 10, 'scope' => ['model' => null, 'surface' => 'code']],
        ['kind' => 'weekly_scoped', 'percent' => 10, 'scope' => ['model' => ['display_name' => null]]],
    ]]);

    expect($limits)->toBe([]);
});

test('ranks the fullest model first', function () {
    $limits = ModelQuotaLimits::from(['limits' => [
        ['kind' => 'weekly_scoped', 'percent' => 12, 'scope' => ['model' => ['display_name' => 'Sonnet']]],
        ['kind' => 'weekly_scoped', 'percent' => 88, 'scope' => ['model' => ['display_name' => 'Opus']]],
        ['kind' => 'weekly_scoped', 'percent' => 40, 'scope' => ['model' => ['display_name' => 'Fable']]],
    ]]);

    expect(array_column($limits, 'model'))->toBe(['Opus', 'Fable', 'Sonnet']);
});

test('reports a snapshot taken before the API sent scoped limits as having none', function () {
    // Real snapshots from 2026-08 carry only session and weekly_all. That is
    // an absence of data, not a zero -- inventing a row would be a wrong
    // answer rather than a missing one.
    $limits = ModelQuotaLimits::from(['limits' => [
        ['kind' => 'session', 'percent' => 0, 'scope' => null],
        ['kind' => 'weekly_all', 'percent' => 0, 'scope' => null],
    ]]);

    expect($limits)->toBe([]);
});

test('returns nothing for a snapshot with no usable body', function (mixed $raw) {
    expect(ModelQuotaLimits::from($raw))->toBe([]);
})->with([
    'empty' => [[]],
    'null' => [null],
    'not an object' => ['garbage'],
    'limits not a list' => [['limits' => 'nope']],
]);
