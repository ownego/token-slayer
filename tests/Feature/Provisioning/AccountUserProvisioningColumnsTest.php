<?php

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('no longer has the legacy provisioning columns on the account_user pivot', function () {
    expect(Schema::hasColumn('account_user', 'token_uuid'))->toBeFalse()
        ->and(Schema::hasColumn('account_user', 'provisioned_at'))->toBeFalse()
        ->and(Schema::hasColumn('account_user', 'claimed_at'))->toBeFalse()
        ->and(Schema::hasColumn('account_user', 'revoked_at'))->toBeFalse()
        ->and(Schema::hasColumn('account_user', 'deprovisioned_at'))->toBeFalse();
});

it('keeps the status column surviving the provisioning-columns drop', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create();
    $user->accounts()->attach($account, ['status' => 'tracked']);

    $pivot = AccountUser::query()->firstOrFail();

    expect(Schema::hasColumn('account_user', 'status'))->toBeTrue()
        ->and($pivot->status->value)->toBe('tracked');
});
