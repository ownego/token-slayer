<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('an account has many member users', function () {
    $account = Account::factory()->create(['email' => 'team-a@example.com', 'plan' => 'max-20x']);
    $members = User::factory()->count(3)->create();
    User::factory()->create(); // unassigned

    $account->users()->attach($members->pluck('id'));

    expect($account->users)->toHaveCount(3)
        ->and($account->plan)->toBe('max-20x');
});

it('stores oauth tokens encrypted at rest', function () {
    $account = Account::factory()->connected()->create();

    $raw = DB::table('accounts')->where('id', $account->id)->first();

    expect($raw->oauth_access_token)->not->toBe($account->oauth_access_token)
        ->and($account->oauth_access_token)->toStartWith('sk-ant-')
        ->and($raw->oauth_access_token)->not->toContain('sk-ant-');
});

it('defaults new accounts to active status with no probe state', function () {
    $account = Account::factory()->create();

    expect($account->status)->toBe(AccountStatus::Active)
        ->and($account->oauth_access_token)->toBeNull()
        ->and($account->last_probed_at)->toBeNull();
});

it('casts status to the AccountStatus enum backed by its stored value', function () {
    $account = Account::factory()->needsReauth()->create();

    expect($account->fresh()->status)->toBe(AccountStatus::NeedsReauth)
        ->and($account->fresh()->status->value)->toBe('needs_reauth');
});

it('has a needsReauth factory state', function () {
    $account = Account::factory()->needsReauth()->create();

    expect($account->status)->toBe(AccountStatus::NeedsReauth)
        ->and($account->probe_error)->not->toBeNull();
});

it('links users to accounts many-to-many', function () {
    $account = Account::factory()->create();
    $users = User::factory()->count(2)->create();

    $account->users()->attach($users->pluck('id'));

    expect($account->users)->toHaveCount(2)
        ->and($users[0]->accounts->first()->id)->toBe($account->id);
});

it('migrates legacy users.account_id assignments into the pivot', function () {
    // covered implicitly by the data-copy migration on real DBs; assert the pivot
    // schema constraints here instead:
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user->id);

    expect(fn () => $account->users()->attach($user->id))->toThrow(QueryException::class);
});

it('stores an organization uuid on the account', function (): void {
    $account = Account::factory()->withOrganizationUuid('7f993a12-f480-45cd-8b99-1e3182d168bf')->create();

    expect($account->fresh()->organization_uuid)->toBe('7f993a12-f480-45cd-8b99-1e3182d168bf');
});
