<?php

use App\Enums\MembershipStatus;
use App\Exceptions\AccountConnectException;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use App\Services\AccountProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

it('writes tracking to the pivot and the encrypted grant to the cache', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'email' => 'ongtung2212002@gmail.com',
        'organization_uuid' => '7f993a12-f480-45cd-8b99-1e3182d168bf',
    ]);

    // Seed the PKCE verifier the way start() would (real prefix: 'account-connect:').
    $state = 'STATE123';
    Cache::put('account-connect:'.$state, ['verifier' => 'VERIFIER'], now()->addMinutes(10));

    fakeAnthropic();
    $token = json_decode(file_get_contents(base_path('tests/fixtures/anthropic/token.json')), true);

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
        ->and($decoded['email'])->toBe('ongtung2212002@gmail.com')
        ->and($decoded['org_uuid'])->toBe('7f993a12-f480-45cd-8b99-1e3182d168bf')
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

it('rejects a pasted code whose authorized identity does not match the target account, writing nothing', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'email' => 'intended-account@org.com',
        'organization_uuid' => 'org-not-in-fixture',
    ]);

    $state = 'STATE456';
    Cache::put('account-connect:'.$state, ['verifier' => 'VERIFIER'], now()->addMinutes(10));

    // Profile fixture authorizes ongtung2212002@gmail.com / org 7f993a12-...
    // — neither matches $account's identity above.
    fakeAnthropic();

    $service = app(AccountProvisioningService::class);

    try {
        $service->provisionFromCode($user, $account, $state, 'THECODE#'.$state);
        $this->fail('Expected AccountConnectException');
    } catch (AccountConnectException $e) {
        expect($e->reason)->toBe('connect_identity_mismatch')
            ->and($e->getMessage())->toContain('ongtung2212002@gmail.com');
    }

    expect(AccountUser::query()->where('user_id', $user->id)->where('account_id', $account->id)->exists())->toBeFalse()
        ->and(Cache::get($service->cacheKey($user->id, $account->id)))->toBeNull();
});

it('memberships lists only tracked orgs, including a tracked row with null provisioned_at', function () {
    $svc = app(App\Services\AccountProvisioningService::class);
    $user = App\Models\User::factory()->create();
    $tracked = App\Models\Account::factory()->create(['organization_uuid' => 'org-tracked']);
    $trackedNoProv = App\Models\Account::factory()->create(['organization_uuid' => 'org-tracked-noprov']);
    $pending = App\Models\Account::factory()->create(['organization_uuid' => 'org-pending']);
    $untracked = App\Models\Account::factory()->create(['organization_uuid' => 'org-untracked']);

    $user->accounts()->syncWithoutDetaching([
        $tracked->id       => ['status' => 'tracked', 'provisioned_at' => now()],
        $trackedNoProv->id => ['status' => 'tracked', 'provisioned_at' => null],
        $pending->id       => ['status' => 'pending', 'provisioned_at' => now()],
        $untracked->id     => ['status' => 'untracked', 'provisioned_at' => now()],
    ]);

    $orgs = collect($svc->memberships($user))->pluck('org_uuid')->all();

    expect($orgs)->toContain('org-tracked')
        ->and($orgs)->toContain('org-tracked-noprov')
        ->and($orgs)->not->toContain('org-pending')
        ->and($orgs)->not->toContain('org-untracked');
});

it('clears deprovisioned_at when an account is re-provisioned', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'email' => 'ongtung2212002@gmail.com',
        'organization_uuid' => '7f993a12-f480-45cd-8b99-1e3182d168bf',
    ]);

    // Seed the PKCE verifier the way start() would (real prefix: 'account-connect:').
    $state = 'STATE789';
    Cache::put('account-connect:'.$state, ['verifier' => 'VERIFIER'], now()->addMinutes(10));

    fakeAnthropic();

    $user->accounts()->syncWithoutDetaching([
        $account->id => ['status' => 'untracked', 'provisioned_at' => now(), 'deprovisioned_at' => now()],
    ]);

    app(AccountProvisioningService::class)
        ->provisionFromCode($user, $account, $state, 'THECODE#'.$state);

    $pivot = AccountUser::query()
        ->where('user_id', $user->id)->where('account_id', $account->id)->firstOrFail();
    expect($pivot->deprovisioned_at)->toBeNull();
});

it('removable lists untracked orgs regardless of provisioned_at, excluding already-deprovisioned', function () {
    $svc = app(App\Services\AccountProvisioningService::class);
    $user = App\Models\User::factory()->create();
    $removed = App\Models\Account::factory()->create(['organization_uuid' => 'org-removed']);
    $removedNoProv = App\Models\Account::factory()->create(['organization_uuid' => 'org-removed-noprov']);
    $alreadyDeprov = App\Models\Account::factory()->create(['organization_uuid' => 'org-done']);
    $tracked = App\Models\Account::factory()->create(['organization_uuid' => 'org-keep']);

    $user->accounts()->syncWithoutDetaching([
        $removed->id       => ['status' => 'untracked', 'provisioned_at' => now(), 'deprovisioned_at' => null],
        $removedNoProv->id => ['status' => 'untracked', 'provisioned_at' => null, 'deprovisioned_at' => null],
        $alreadyDeprov->id => ['status' => 'untracked', 'provisioned_at' => now(), 'deprovisioned_at' => now()],
        $tracked->id       => ['status' => 'tracked', 'provisioned_at' => now()],
    ]);

    $orgs = collect($svc->removable($user))->pluck('org_uuid')->all();

    expect($orgs)->toContain('org-removed')
        ->and($orgs)->toContain('org-removed-noprov')
        ->and($orgs)->not->toContain('org-done')
        ->and($orgs)->not->toContain('org-keep');
});
