<?php

use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use App\Services\Analytics\TokenVolumeQuery;
use App\Services\Analytics\UsageFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it groups token volume by day and provider within the range', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['provider' => 'claude-code', 'tokens' => 100, 'created_at' => now()->subDays(2)]);
    Event::factory()->for($user)->create(['provider' => 'claude-code', 'tokens' => 50, 'created_at' => now()->subDays(2)]);
    Event::factory()->for($user)->create(['provider' => 'codex', 'tokens' => 30, 'created_at' => now()->subDays(2)]);
    Event::factory()->for($user)->create(['provider' => 'claude-code', 'tokens' => 999, 'created_at' => now()->subDays(60)]); // out of range

    $rows = app(TokenVolumeQuery::class)->get(
        new UsageFilters(now()->subDays(7), now(), null, null, null)
    );

    $cc = collect($rows)->firstWhere('provider', 'claude-code');
    $codex = collect($rows)->firstWhere('provider', 'codex');

    expect($cc['tokens'])->toBe(150)
        ->and($codex['tokens'])->toBe(30)
        ->and(collect($rows)->pluck('tokens')->sum())->toBe(180); // 999 excluded
});

test('it narrows token volume to a single provider when filtered', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['provider' => 'claude-code', 'tokens' => 100, 'created_at' => now()->subDay()]);
    Event::factory()->for($user)->create(['provider' => 'codex', 'tokens' => 40, 'created_at' => now()->subDay()]);

    $rows = app(TokenVolumeQuery::class)->get(
        new UsageFilters(now()->subDays(7), now(), null, 'codex', null)
    );

    expect(collect($rows)->pluck('provider')->unique()->all())->toBe(['codex'])
        ->and(collect($rows)->pluck('tokens')->sum())->toBe(40);
});

test('it narrows token volume to a single account when filtered', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create();
    Event::factory()->for($user)->create(['account_id' => $account->id, 'tokens' => 70, 'created_at' => now()->subDay()]);
    Event::factory()->for($user)->create(['account_id' => null, 'tokens' => 500, 'created_at' => now()->subDay()]);

    $rows = app(TokenVolumeQuery::class)->get(
        new UsageFilters(now()->subDays(7), now(), $account->id, null, null)
    );

    expect(collect($rows)->pluck('tokens')->sum())->toBe(70);
});
