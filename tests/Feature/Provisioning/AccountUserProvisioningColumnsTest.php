<?php

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('stores per-user provisioning tracking on the account_user pivot', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create();
    $user->accounts()->attach($account, [
        'token_uuid' => 'tok-uuid-1',
        'provisioned_at' => now(),
        'claimed_at' => null,
        'revoked_at' => null,
    ]);

    $pivot = AccountUser::query()->firstOrFail();
    expect($pivot->token_uuid)->toBe('tok-uuid-1')
        ->and($pivot->provisioned_at)->not->toBeNull()
        ->and($pivot->provisioned_at)->toBeInstanceOf(Carbon::class)
        ->and($pivot->claimed_at)->toBeNull()
        ->and($pivot->revoked_at)->toBeNull();
});
