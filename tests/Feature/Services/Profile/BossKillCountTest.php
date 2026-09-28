<?php

use App\Models\Boss;
use App\Models\User;
use App\Services\Profile\BossKillCount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('boss kills count only the killing blows, with the latest named', function () {
    $me = User::factory()->create();
    Boss::factory()->defeated()->create(['number' => 3, 'name' => 'SLIME', 'killing_blow_user_id' => $me->id, 'defeated_at' => '2026-09-20 10:00:00']);
    Boss::factory()->defeated()->create(['number' => 5, 'name' => 'CTHULHU', 'killing_blow_user_id' => $me->id, 'defeated_at' => '2026-09-25 10:00:00']);
    Boss::factory()->defeated()->create(['number' => 4, 'killing_blow_user_id' => User::factory()->create()->id]);

    $kills = app(BossKillCount::class)->for($me);

    expect($kills['count'])->toBe(2)->and($kills['latest']['name'])->toBe('CTHULHU');
});

test('a fighter with no kills gets a zero count and a null latest', function () {
    $kills = app(BossKillCount::class)->for(User::factory()->create());

    expect($kills)->toBe(['count' => 0, 'latest' => null]);
});
