<?php

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

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

it('has a nullable deprovisioned_at column defaulting to null', function () {
    $user = App\Models\User::factory()->create();
    $account = App\Models\Account::factory()->create();
    $user->accounts()->syncWithoutDetaching([$account->id => ['status' => 'tracked']]);

    $pivot = App\Models\AccountUser::query()
        ->where('user_id', $user->id)->where('account_id', $account->id)->firstOrFail();

    expect($pivot->deprovisioned_at)->toBeNull();
    expect(Schema::hasColumn('account_user', 'deprovisioned_at'))->toBeTrue();
});
