<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\User;
use App\Services\Profile\AccountQuotaCards;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('quota cards are the user\'s own accounts only', function () {
    $me = User::factory()->create();
    $mine = Account::factory()->create();
    Account::factory()->create(); // someone else's
    $me->accounts()->attach($mine);

    expect(array_column(app(AccountQuotaCards::class)->for($me), 'account_id'))->toBe([$mine->id]);
});

test('each card carries the account\'s tracked members', function () {
    $me = User::factory()->create();
    $mate = User::factory()->create();
    $account = Account::factory()->create();
    $me->accounts()->attach($account);
    $account->users()->attach($mate);

    $cards = app(AccountQuotaCards::class)->for($me);

    expect(array_column($cards[0]['members'], 'user_id'))->toBe([$me->id, $mate->id]);
});

test('a fighter with no accounts gets an empty list', function () {
    expect(app(AccountQuotaCards::class)->for(User::factory()->create()))->toBe([]);
});

test('each card carries its own probe error, if any', function () {
    $me = User::factory()->create();
    $account = Account::factory()->create(['probe_error' => 'token rejected']);
    $me->accounts()->attach($account);

    expect(app(AccountQuotaCards::class)->for($me)[0]['probe_error'])->toBe('token rejected');
});

test('an account the player only shows up on as untracked gets no card', function () {
    // Untracked rows are materialized from any event on the account; only a
    // membership an admin promoted to tracked belongs on the sheet.
    $me = User::factory()->create();
    $tracked = Account::factory()->create();
    $untracked = Account::factory()->create();
    $me->accounts()->attach($tracked, ['status' => MembershipStatus::Tracked->value]);
    $me->accounts()->attach($untracked, ['status' => MembershipStatus::Untracked->value]);

    expect(array_column(app(AccountQuotaCards::class)->for($me), 'account_id'))->toBe([$tracked->id]);
});
