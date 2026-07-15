<?php

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('deletes snapshots older than 30 days and keeps newer ones', function () {
    $account = Account::factory()->connected()->create();

    $old = AccountUsageSnapshot::factory()->for($account)->create(['created_at' => now()->subDays(31)]);
    $boundary = AccountUsageSnapshot::factory()->for($account)->create(['created_at' => now()->subDays(30)->subMinute()]);
    $recent = AccountUsageSnapshot::factory()->for($account)->create(['created_at' => now()->subDays(29)]);

    $this->artisan('accounts:prune-usage-snapshots')->assertSuccessful();

    expect(AccountUsageSnapshot::find($old->id))->toBeNull()
        ->and(AccountUsageSnapshot::find($boundary->id))->toBeNull()
        ->and(AccountUsageSnapshot::find($recent->id))->not->toBeNull();
});

test('reports the deleted count', function () {
    $account = Account::factory()->connected()->create();
    AccountUsageSnapshot::factory()->for($account)->create(['created_at' => now()->subDays(45)]);
    AccountUsageSnapshot::factory()->for($account)->create(['created_at' => now()->subDays(60)]);

    $this->artisan('accounts:prune-usage-snapshots')
        ->expectsOutputToContain('pruned 2 usage snapshots')
        ->assertSuccessful();
});
