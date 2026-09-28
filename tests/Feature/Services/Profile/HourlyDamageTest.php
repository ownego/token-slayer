<?php

use App\Models\Event;
use App\Models\User;
use App\Services\Profile\HourlyDamage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('returns exactly 24 local-hour buckets ending with the current hour, across midnight', function () {
    Carbon::setTestNow('2026-09-27 17:20:00');   // 00:20 local on the 28th
    $me = User::factory()->create();
    Event::factory()->create(['user_id' => $me->id, 'tokens' => 5, 'created_at' => '2026-09-27 17:05:00']);  // 00:05 local → last bucket
    Event::factory()->create(['user_id' => $me->id, 'tokens' => 7, 'created_at' => '2026-09-27 16:50:00']);  // 23:50 local → previous
    Event::factory()->create(['user_id' => $me->id, 'tokens' => 9, 'created_at' => '2026-09-26 16:00:00']);  // > 24 h ago → excluded

    $buckets = app(HourlyDamage::class)->last24($me);

    expect($buckets)->toHaveCount(24)
        ->and($buckets[23])->toBe(['hour' => '00:00', 'damage' => 5])
        ->and($buckets[22])->toBe(['hour' => '23:00', 'damage' => 7])
        ->and(array_sum(array_column($buckets, 'damage')))->toBe(12);
});
