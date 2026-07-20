<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('flips a pending provisioned membership to tracked and sets claimed_at', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['organization_uuid' => '11111111-1111-4111-8111-111111111111']);
    $user->accounts()->syncWithoutDetaching([
        $account->id => ['status' => MembershipStatus::Pending->value, 'provisioned_at' => now()],
    ]);

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [['org_uuid' => '11111111-1111-4111-8111-111111111111']]]);

    $res->assertOk()->assertJson(['confirmed' => 1]);

    $pivot = AccountUser::query()
        ->where('user_id', $user->id)->where('account_id', $account->id)->firstOrFail();
    expect($pivot->status)->toBe(MembershipStatus::Tracked)
        ->and($pivot->claimed_at)->not->toBeNull();
});

it('skips an org uuid the user has no provisioned pivot for, without creating a membership', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['organization_uuid' => '22222222-2222-4222-8222-222222222222']);

    expect(AccountUser::query()->where('user_id', $user->id)->where('account_id', $account->id)->exists())
        ->toBeFalse();

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [['org_uuid' => '22222222-2222-4222-8222-222222222222']]]);

    $res->assertOk()->assertJson(['confirmed' => 0]);

    expect(AccountUser::query()->where('user_id', $user->id)->where('account_id', $account->id)->exists())
        ->toBeFalse();
});

it('skips an org uuid where the user has a pivot that was never provisioned (self-graft attempt)', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['organization_uuid' => '88888888-8888-4888-8888-888888888888']);
    $user->accounts()->syncWithoutDetaching([
        $account->id => ['status' => MembershipStatus::Untracked->value],
    ]);

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [['org_uuid' => '88888888-8888-4888-8888-888888888888']]]);

    $res->assertOk()->assertJson(['confirmed' => 0]);

    $pivot = AccountUser::query()
        ->where('user_id', $user->id)->where('account_id', $account->id)->firstOrFail();
    expect($pivot->status)->toBe(MembershipStatus::Untracked);
});

it('ignores an unknown org uuid without creating an account or erroring', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);

    $unknownOrgUuid = '99999999-9999-4999-8999-999999999999';
    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [['org_uuid' => $unknownOrgUuid]]]);

    $res->assertOk()->assertJson(['confirmed' => 0]);
    expect(Account::query()->where('organization_uuid', $unknownOrgUuid)->exists())->toBeFalse();
});

it('does not touch a membership on a different account absent from the list (additive only)', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $confirmedAccount = Account::factory()->create(['organization_uuid' => '33333333-3333-4333-8333-333333333333']);
    $untouchedAccount = Account::factory()->create(['organization_uuid' => '44444444-4444-4444-8444-444444444444']);

    $user->accounts()->syncWithoutDetaching([
        $confirmedAccount->id => ['status' => MembershipStatus::Pending->value, 'provisioned_at' => now()],
        $untouchedAccount->id => ['status' => MembershipStatus::Untracked->value],
    ]);

    $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [['org_uuid' => '33333333-3333-4333-8333-333333333333']]])
        ->assertOk()->assertJson(['confirmed' => 1]);

    $untouched = AccountUser::query()
        ->where('user_id', $user->id)->where('account_id', $untouchedAccount->id)->firstOrFail();
    expect($untouched->status)->toBe(MembershipStatus::Untracked)
        ->and($untouched->claimed_at)->toBeNull();
});

it('confirms only the provisioned org in a multi-org batch and ignores the unknown one', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $knownAccount = Account::factory()->create(['organization_uuid' => '55555555-5555-4555-8555-555555555555']);
    $user->accounts()->syncWithoutDetaching([
        $knownAccount->id => ['status' => MembershipStatus::Pending->value, 'provisioned_at' => now()],
    ]);
    $unknownOrgUuid = '66666666-6666-4666-8666-666666666666';

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [
            ['org_uuid' => '55555555-5555-4555-8555-555555555555'],
            ['org_uuid' => $unknownOrgUuid],
        ]]);

    $res->assertOk()->assertJson(['confirmed' => 1]);

    $pivot = AccountUser::query()
        ->where('user_id', $user->id)->where('account_id', $knownAccount->id)->firstOrFail();
    expect($pivot->status)->toBe(MembershipStatus::Tracked);
    expect(Account::query()->where('organization_uuid', $unknownOrgUuid)->exists())->toBeFalse();
});

it('dedupes a repeated org uuid so it only counts once', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['organization_uuid' => '10101010-1010-4010-8010-101010101010']);
    $user->accounts()->syncWithoutDetaching([
        $account->id => ['status' => MembershipStatus::Pending->value, 'provisioned_at' => now()],
    ]);

    $res = $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [
            ['org_uuid' => '10101010-1010-4010-8010-101010101010'],
            ['org_uuid' => '10101010-1010-4010-8010-101010101010'],
        ]]);

    $res->assertOk()->assertJson(['confirmed' => 1]);
});

it('leaves an already-set claimed_at unchanged when reconfirmed', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);
    $account = Account::factory()->create(['organization_uuid' => '77777777-7777-4777-8777-777777777777']);
    $claimedAt = now()->subHour()->startOfSecond();
    $user->accounts()->syncWithoutDetaching([
        $account->id => [
            'status' => MembershipStatus::Pending->value,
            'provisioned_at' => now()->subHours(2),
            'claimed_at' => $claimedAt,
        ],
    ]);

    $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [['org_uuid' => '77777777-7777-4777-8777-777777777777']]])
        ->assertOk()->assertJson(['confirmed' => 1]);

    $pivot = AccountUser::query()
        ->where('user_id', $user->id)->where('account_id', $account->id)->firstOrFail();
    expect($pivot->status)->toBe(MembershipStatus::Tracked)
        ->and($pivot->claimed_at->eq($claimedAt))->toBeTrue();
});

it('rejects an unauthenticated request with no hook token', function () {
    $this->postJson('/api/provisioned/confirm', ['accounts' => [['org_uuid' => '11111111-1111-4111-8111-111111111111']]])
        ->assertStatus(401);
});

it('rejects a malformed body with a 422', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'HOOKTOK')]);

    $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => 'not-an-array'])
        ->assertStatus(422);

    $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', ['accounts' => [['org_uuid' => 'not-a-uuid']]])
        ->assertStatus(422);

    $this->withHeader('Authorization', 'Bearer HOOKTOK')
        ->postJson('/api/provisioned/confirm', [])
        ->assertStatus(422);
});
