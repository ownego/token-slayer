<?php

use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\Device;
use App\Models\User;
use App\Services\Provisioning\GrantSessionExpiryBackfiller;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('estimates a live grant own session deadline from its provisioned_at when nothing was ever recorded', function (): void {
    $account = Account::factory()->create();
    $device = Device::factory()->for(User::factory())->create();
    $grant = AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create([
        'provisioned_at' => now()->subDays(10),
        'session_expires_at' => null,
    ]);

    $count = app(GrantSessionExpiryBackfiller::class)->backfill();

    expect($count)->toBe(1);
    $grant->refresh();
    expect($grant->session_expires_at->timestamp)
        ->toBe($grant->provisioned_at->copy()->addSeconds(GrantSessionExpiryBackfiller::TYPICAL_SESSION_LIFETIME_SECONDS)->timestamp)
        ->and($grant->session_expires_at_estimated)->toBeTrue();
});

it('leaves a grant alone that already carries a real recorded deadline', function (): void {
    $account = Account::factory()->create();
    $device = Device::factory()->for(User::factory())->create();
    $realDeadline = now()->addDays(15);
    $grant = AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create([
        'session_expires_at' => $realDeadline,
        'session_expires_at_estimated' => false,
    ]);

    $count = app(GrantSessionExpiryBackfiller::class)->backfill();

    expect($count)->toBe(0);
    expect($grant->fresh()->session_expires_at->timestamp)->toBe($realDeadline->timestamp)
        ->and($grant->fresh()->session_expires_at_estimated)->toBeFalse();
});

it('skips a revoked grant even with no recorded deadline', function (): void {
    $account = Account::factory()->create();
    $device = Device::factory()->for(User::factory())->create();
    $grant = AccountProvisionedGrant::factory()->for($account)->for($device)->revoked()->create([
        'session_expires_at' => null,
    ]);

    app(GrantSessionExpiryBackfiller::class)->backfill();

    expect($grant->fresh()->session_expires_at)->toBeNull();
});
