<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use App\Services\Accounts\HistoricalMembershipBackfiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('materializes untracked rows for historical contributors', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create();
    Event::factory()->count(2)->for($user)->for($account)->create();

    $created = app(HistoricalMembershipBackfiller::class)->backfill();

    expect($created)->toBe(1);
    expect($account->untrackedUsers()->whereKey($user->id)->exists())->toBeTrue();
});

it('never downgrades a tracked row and is idempotent', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user, ['status' => MembershipStatus::Tracked->value]);
    Event::factory()->for($user)->for($account)->create();

    app(HistoricalMembershipBackfiller::class)->backfill();
    $created = app(HistoricalMembershipBackfiller::class)->backfill();

    expect($created)->toBe(0);
    expect(DB::table('account_user')->where(['account_id' => $account->id, 'user_id' => $user->id])->value('status'))
        ->toBe(MembershipStatus::Tracked->value);
});
