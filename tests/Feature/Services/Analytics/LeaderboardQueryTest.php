<?php

use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use App\Services\Analytics\TopAccountsQuery;
use App\Services\Analytics\TopUsersQuery;
use App\Services\Analytics\UsageFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it ranks users by tokens in the range and respects the limit', function () {
    $heavy = User::factory()->create(['slack_handle' => 'heavy']);
    $light = User::factory()->create(['slack_handle' => 'light']);
    Event::factory()->for($heavy)->create(['tokens' => 900, 'created_at' => now()->subDay()]);
    Event::factory()->for($light)->create(['tokens' => 100, 'created_at' => now()->subDay()]);

    $rows = app(TopUsersQuery::class)->get(
        new UsageFilters(now()->subDays(7), now(), null, null, null),
        1
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['handle'])->toBe('heavy')
        ->and($rows[0]['tokens'])->toBe(900);
});

test('it ranks accounts and collapses unassigned events into one row', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['email' => 'team@example.com']);
    Event::factory()->for($user)->create(['account_id' => $account->id, 'tokens' => 300, 'created_at' => now()->subDay()]);
    Event::factory()->for($user)->create(['account_id' => null, 'tokens' => 80, 'created_at' => now()->subDay()]);
    Event::factory()->for($user)->create(['account_id' => null, 'tokens' => 20, 'created_at' => now()->subDay()]);

    $rows = app(TopAccountsQuery::class)->get(
        new UsageFilters(now()->subDays(7), now(), null, null, null),
        10
    );

    $team = collect($rows)->firstWhere('email', 'team@example.com');
    $unassigned = collect($rows)->firstWhere('email', '— unassigned —');

    expect($team['tokens'])->toBe(300)
        ->and($unassigned['tokens'])->toBe(100)
        ->and($unassigned['account_id'])->toBeNull();
});
