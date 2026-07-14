<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use App\Services\AccountProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

it('revokes a provision: sets revoked_at and forgets the cached grant', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create();
    $user->accounts()->syncWithoutDetaching([$account->id => [
        'status' => MembershipStatus::Tracked->value,
        'token_uuid' => 'tok-1',
        'provisioned_at' => now(),
    ]]);
    // A cached secret exists (as provisionFromCode would have written).
    $key = 'provisioned:setup:'.$user->id.':'.$account->id;
    Cache::put($key, Crypt::encryptString(json_encode(['access_token' => 'sk-ant-oat01-ACCESS'])), 86400);

    $pivot = AccountUser::query()->firstOrFail();
    // Call the revoke path directly (the action delegates to this).
    app(AccountProvisioningService::class)->revoke($pivot);

    $pivot->refresh();
    expect($pivot->revoked_at)->not->toBeNull()
        ->and(Cache::get($key))->toBeNull(); // cached grant forgotten
});
