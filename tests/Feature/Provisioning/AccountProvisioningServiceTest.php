<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\User;
use App\Services\AccountProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('writes tracking to the pivot and the encrypted grant to the cache', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['email' => 'shared@org.com', 'organization_uuid' => 'org-1']);

    // Seed the PKCE verifier the way start() would (real prefix: 'account-connect:').
    $state = 'STATE123';
    Cache::put('account-connect:'.$state, ['verifier' => 'VERIFIER'], now()->addMinutes(10));

    // Fake the Anthropic token endpoint with the canonical fixture.
    $token = json_decode(file_get_contents(base_path('tests/fixtures/anthropic/token.json')), true);
    Http::fake(['*/v1/oauth/token' => Http::response($token, 200)]);

    $service = app(AccountProvisioningService::class);
    $pivot = $service->provisionFromCode($user, $account, $state, 'THECODE#'.$state);

    // Pivot holds ONLY tracking — no token columns exist to hold a secret.
    expect($pivot->token_uuid)->toBe($token['token_uuid'])
        ->and($pivot->provisioned_at)->not->toBeNull()
        ->and($pivot->claimed_at)->toBeNull()
        ->and($pivot->status)->toBe(MembershipStatus::Tracked);

    // The raw grant is in the cache, ENCRYPTED, and decrypts to the wire shape.
    $key = $service->cacheKey($user->id, $account->id);
    $raw = Cache::get($key);
    expect($raw)->not->toBeNull()->and($raw)->not->toContain($token['access_token']); // encrypted at rest
    $decoded = json_decode(Crypt::decryptString($raw), true);
    expect($decoded['access_token'])->toBe($token['access_token'])
        ->and($decoded['refresh_token'])->toBe($token['refresh_token'])
        ->and($decoded['email'])->toBe('shared@org.com')
        ->and($decoded['org_uuid'])->toBe('org-1')
        ->and($decoded['expires_at'])->toBeInt();

    // claimableFor returns provisioned rows INCLUDING already-claimed ones
    // (setup is idempotent while the cache secret lives); only revoked rows
    // are excluded. So the original + the claimed row = 2; revoked dropped.
    $claimed = Account::factory()->create();
    $revoked = Account::factory()->create();
    $user->accounts()->syncWithoutDetaching([
        $claimed->id => ['status' => MembershipStatus::Tracked->value, 'provisioned_at' => now(), 'claimed_at' => now()],
        $revoked->id => ['status' => MembershipStatus::Tracked->value, 'provisioned_at' => now(), 'revoked_at' => now()],
    ]);
    expect($service->claimableFor($user))->toHaveCount(2);
});
