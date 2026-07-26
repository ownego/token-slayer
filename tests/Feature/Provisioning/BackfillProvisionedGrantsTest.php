<?php

use App\Enums\GrantStatus;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\Device;
use App\Models\User;
use App\Services\Provisioning\LegacyGrantBackfiller;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// The backfiller takes the legacy rows as plain arrays (the migration reads
// account_user itself; the test passes literals) — so this test stays valid
// even after the drop migration removes the legacy columns from the schema.

it('backfills one default device and mapped grants per legacy pivot row', function () {
    $user = User::factory()->create();
    $claimed = Account::factory()->create();
    $pendingAlive = Account::factory()->create();
    $revoked = Account::factory()->create();

    $rows = [
        [$claimed->id, ['provisioned_at' => now()->subDays(3), 'claimed_at' => now()->subDays(3), 'token_uuid' => 't1']],
        [$pendingAlive->id, ['provisioned_at' => now()->subHour(), 'claimed_at' => null, 'token_uuid' => 't2']],
        [$revoked->id, ['provisioned_at' => now()->subDays(9), 'claimed_at' => now()->subDays(9), 'revoked_at' => now()->subDay(), 'token_uuid' => 't3']],
    ];
    $legacy = collect($rows)->map(fn (array $r) => array_merge([
        'user_id' => $user->id, 'account_id' => $r[0], 'status' => 'tracked',
        'created_at' => now(), 'updated_at' => now(),
    ], $r[1]))->all();
    Cache::put(CacheKeys::legacyProvisionedSetup($user->id, $pendingAlive->id), 'ALIVE-SECRET', 3600);

    app(LegacyGrantBackfiller::class)->backfill($legacy);

    $device = Device::query()->where('user_id', $user->id)->firstOrFail();
    expect($device->device_id)->toBe(Device::DEFAULT_NAME)
        ->and($device->name)->toBe('Default')
        ->and(AccountProvisionedGrant::query()->count())->toBe(3);

    $byAccount = AccountProvisionedGrant::query()->get()->keyBy('account_id');
    expect($byAccount[$claimed->id]->status)->toBe(GrantStatus::Claimed)
        ->and($byAccount[$pendingAlive->id]->status)->toBe(GrantStatus::Pending)
        ->and($byAccount[$revoked->id]->status)->toBe(GrantStatus::Revoked)
        ->and(Cache::get(CacheKeys::provisionedGrant($byAccount[$pendingAlive->id]->id)))->toBe('ALIVE-SECRET');
});
