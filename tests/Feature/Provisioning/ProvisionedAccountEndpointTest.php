<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

// Seed a provisioned pivot row + (optionally) its encrypted cache secret,
// exactly as provisionFromCode would.
function provisionRow(User $user, Account $account, array $pivot = [], ?array $secret = null): void
{
    $user->accounts()->syncWithoutDetaching([$account->id => array_merge([
        'status' => MembershipStatus::Tracked->value,
        'token_uuid' => 'tok-'.$account->id,
        'provisioned_at' => now(),
    ], $pivot)]);

    if ($secret !== null) {
        Cache::put(
            'provisioned:setup:'.$user->id.':'.$account->id,
            Crypt::encryptString(json_encode($secret)),
            86400,
        );
    }
}

it('returns the authed user\'s grants from cache, idempotently while the cache lives', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['email' => 'shared@org.com', 'organization_uuid' => 'org-1']);
    provisionRow($user, $account, secret: [
        'name' => 'shared@org.com', 'email' => 'shared@org.com', 'org_uuid' => 'org-1',
        'access_token' => 'sk-ant-oat01-ACCESS', 'refresh_token' => 'sk-ant-ort01-REFRESH',
        'expires_at' => 1_800_000_000,
    ]);

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')->getJson('/api/provisioned');
    $res->assertOk()
        ->assertJsonPath('accounts.0.email', 'shared@org.com')
        ->assertJsonPath('accounts.0.org_uuid', 'org-1')
        ->assertJsonPath('accounts.0.access_token', 'sk-ant-oat01-ACCESS')
        ->assertJsonPath('accounts.0.refresh_token', 'sk-ant-ort01-REFRESH')
        ->assertJsonPath('accounts.0.expires_at', 1_800_000_000);

    // First pull records the claim time.
    $pivot = AccountUser::query()
        ->where('user_id', $user->id)->where('account_id', $account->id)->firstOrFail();
    expect($pivot->claimed_at)->not->toBeNull();

    // Idempotent: a second pull STILL returns it (the cache secret is not consumed).
    $this->withHeader('Authorization', 'Bearer HOOKTOK')->getJson('/api/provisioned')
        ->assertOk()->assertJsonCount(1, 'accounts')
        ->assertJsonPath('accounts.0.access_token', 'sk-ant-oat01-ACCESS');

    // Once the cache secret is gone (24h TTL elapsed / revoked), it is no longer served.
    Cache::forget('provisioned:setup:'.$user->id.':'.$account->id);
    $this->withHeader('Authorization', 'Bearer HOOKTOK')->getJson('/api/provisioned')
        ->assertOk()->assertJsonCount(0, 'accounts');
});

it('excludes another user\'s, a revoked, and an expired-cache grant', function () {
    $me = User::factory()->create(['hook_token' => hash('sha256', 'MINE')]);
    $other = User::factory()->create();

    provisionRow($other, Account::factory()->create(), secret: ['name' => 'x', 'email' => 'x', 'org_uuid' => null,
        'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => 1]);         // not mine
    provisionRow($me, Account::factory()->create(), ['revoked_at' => now()]);        // revoked (+ no cache)
    provisionRow($me, Account::factory()->create());                                 // provisioned but cache expired (no secret)

    $this->withHeader('Authorization', 'Bearer MINE')->getJson('/api/provisioned')
        ->assertOk()->assertJsonCount(0, 'accounts');
});
