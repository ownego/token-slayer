<?php

use App\Models\Event;
use App\Models\User;
use App\Services\Profile\DamageByModel;
use App\Services\Profile\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('damage is grouped per model, highest first, with shares', function () {
    Carbon::setTestNow('2026-09-27 05:00:00');
    $me = User::factory()->create();
    foreach ([['claude-opus-5-5', 600], ['claude-sonnet-5', 300], ['claude-opus-5-5', 100]] as [$model, $tokens]) {
        Event::factory()->create(['user_id' => $me->id, 'model' => $model, 'tokens' => $tokens, 'created_at' => now()->subMinutes(5)]);
    }

    $rows = app(DamageByModel::class)->for($me, Period::Today);

    expect(array_column($rows, 'model'))->toBe(['claude-opus-5-5', 'claude-sonnet-5'])
        ->and($rows[0]['damage'])->toBe(700)
        ->and($rows[0]['share'])->toBe(0.7);
});

test('a user with no events gets an empty list, not a divide-by-zero', function () {
    expect(app(DamageByModel::class)->for(User::factory()->create(), Period::All))->toBe([]);
});
