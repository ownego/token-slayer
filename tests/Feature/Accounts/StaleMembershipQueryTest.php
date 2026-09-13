<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\RebalanceWindow;
use App\Services\Accounts\StaleMembershipQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('finds a seat still held on an account somebody migrated away from', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $old = Account::factory()->connected()->create();
    $new = Account::factory()->connected()->create();
    $user = User::factory()->create();

    livesOn($old, $user, '2026-08-16 09:00:00', 10, 1_000_000);
    livesOn($new, $user, '2026-09-07 09:00:00', 5, 400_000);

    $stale = app(StaleMembershipQuery::class)->get(collect([$old, $new]), RebalanceWindow::days(30));

    // Reclaiming this costs nobody a re-authentication — they already work
    // somewhere else. It is the cheapest capacity on the page.
    expect($stale)->toHaveCount(1)
        ->and($stale[0]['user_id'])->toBe($user->id)
        ->and($stale[0]['account_id'])->toBe($old->id)
        ->and($stale[0]['last_used_at'])->not->toBeNull();

    Carbon::setTestNow();
});

it('leaves alone a member who is still working on the account', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $account = Account::factory()->connected()->create();
    $user = User::factory()->create();
    livesOn($account, $user, '2026-09-08 09:00:00', 4, 500_000);

    expect(app(StaleMembershipQuery::class)->get(collect([$account]), RebalanceWindow::days(30)))->toBe([]);

    Carbon::setTestNow();
});

it('does not call a freshly granted seat stale before anyone could use it', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $account = Account::factory()->connected()->create();
    $user = User::factory()->create();

    // Pending: the grant exists, the person has not claimed it yet. Nothing
    // about that is abandoned, and reclaiming it would undo a setup that is
    // still in progress.
    $account->users()->syncWithoutDetaching([$user->id => ['status' => MembershipStatus::Pending->value]]);

    expect(app(StaleMembershipQuery::class)->get(collect([$account]), RebalanceWindow::days(30)))->toBe([]);

    Carbon::setTestNow();
});

it('keeps a single-account member even when they have gone quiet', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $account = Account::factory()->connected()->create();
    $user = User::factory()->create();
    livesOn($account, $user, '2026-08-16 09:00:00', 3, 500_000);

    // Silence is a holiday, not a migration: they have nowhere else to be,
    // so taking their only account away would just break them.
    expect(app(StaleMembershipQuery::class)->get(collect([$account]), RebalanceWindow::days(30)))->toBe([]);

    Carbon::setTestNow();
});
