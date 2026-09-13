<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\HomeAccountResolver;
use App\Services\Accounts\RebalanceWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('credits someone to the account they moved to, not the one they left behind', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $old = Account::factory()->connected()->create();
    $new = Account::factory()->connected()->create();
    $user = User::factory()->create();

    // Three heavy weeks on the old account, then a clean handover — exactly
    // the shape of a real migration. Summing the whole range would still
    // call the old account home for weeks afterwards, and every plan built
    // on that would be proposing moves off an account nobody is on.
    livesOn($old, $user, '2026-08-16 09:00:00', 10, 1_000_000);
    livesOn($new, $user, '2026-09-07 09:00:00', 5, 400_000);

    $homes = app(HomeAccountResolver::class)->resolve(collect([$old, $new]), RebalanceWindow::days(30));

    expect($homes[$user->id])->toBe($new->id);

    Carbon::setTestNow();
});

it('falls back to the whole range for someone who has been away', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $main = Account::factory()->connected()->create();
    $other = Account::factory()->connected()->create();
    $user = User::factory()->create();

    livesOn($main, $user, '2026-08-18 09:00:00', 7, 1_000_000);
    livesOn($other, $user, '2026-08-18 09:00:00', 2, 100_000);

    $homes = app(HomeAccountResolver::class)->resolve(collect([$main, $other]), RebalanceWindow::days(30));

    // No recent usage anywhere: a fortnight of silence is not evidence they
    // have moved, so the range they did work in decides.
    expect($homes[$user->id])->toBe($main->id);

    Carbon::setTestNow();
});

it('ignores a brief spill onto another account', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $home = Account::factory()->connected()->create();
    $spill = Account::factory()->connected()->create();
    $user = User::factory()->create();

    // Two days elsewhere in the middle of a week that is otherwise all on
    // one account: someone bounced off a full account and came straight
    // back, which is not a change of home.
    livesOn($home, $user, '2026-09-06 09:00:00', 6, 1_000_000);
    livesOn($spill, $user, '2026-09-08 14:00:00', 2, 300_000);

    $homes = app(HomeAccountResolver::class)->resolve(collect([$home, $spill]), RebalanceWindow::days(30));

    expect($homes[$user->id])->toBe($home->id);

    Carbon::setTestNow();
});

it('leaves a member with no usage at all on the account that granted them a seat', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $account = Account::factory()->connected()->create();
    $user = User::factory()->create();
    $account->users()->syncWithoutDetaching([$user->id => ['status' => MembershipStatus::Pending->value]]);

    $homes = app(HomeAccountResolver::class)->resolve(collect([$account]), RebalanceWindow::days(30));

    expect($homes[$user->id])->toBe($account->id);

    Carbon::setTestNow();
});
