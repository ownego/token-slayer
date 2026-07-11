<?php

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('round-trips snapshot fields including the raw payload array', function (): void {
    $account = Account::factory()->create();

    $snapshot = AccountUsageSnapshot::factory()->create([
        'account_id' => $account->id,
        'util_5h' => 42,
        'util_7d' => 63,
        'util_7d_sonnet' => 10,
        'util_7d_oi' => 5,
        'reset_5h_at' => now()->addHours(5),
        'reset_7d_at' => now()->addDays(7),
        'raw' => ['five_hour' => ['utilization' => 42]],
    ]);

    $fresh = $snapshot->fresh();

    expect($fresh->account_id)->toBe($account->id)
        ->and($fresh->util_5h)->toBe(42)
        ->and($fresh->util_7d)->toBe(63)
        ->and($fresh->util_7d_sonnet)->toBe(10)
        ->and($fresh->util_7d_oi)->toBe(5)
        ->and($fresh->reset_5h_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->reset_7d_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->raw)->toBe(['five_hour' => ['utilization' => 42]])
        ->and($fresh->created_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->updated_at)->toBeNull();
});

it('deletes snapshots when the owning account is deleted', function (): void {
    $account = Account::factory()->create();
    AccountUsageSnapshot::factory()->count(2)->create(['account_id' => $account->id]);

    expect(AccountUsageSnapshot::count())->toBe(2);

    $account->delete();

    expect(AccountUsageSnapshot::count())->toBe(0);
});

it('exposes usageSnapshots as a hasMany relation on Account', function (): void {
    $account = Account::factory()->create();
    AccountUsageSnapshot::factory()->count(3)->create(['account_id' => $account->id]);

    expect($account->usageSnapshots)->toHaveCount(3);
});

it('resolves latestUsageSnapshot to the newest of several', function (): void {
    $account = Account::factory()->create();

    $older = AccountUsageSnapshot::factory()->create([
        'account_id' => $account->id,
        'created_at' => now()->subHour(),
    ]);
    $newer = AccountUsageSnapshot::factory()->create([
        'account_id' => $account->id,
        'created_at' => now(),
    ]);

    expect($account->latestUsageSnapshot->id)->toBe($newer->id)
        ->and($account->latestUsageSnapshot->id)->not->toBe($older->id);
});
