<?php

use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\RebalanceWindow;
use App\Services\Accounts\StaleMembershipQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

it('does not offer to release a seat that was only just granted', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $home = Account::factory()->connected()->create();
    $justGranted = Account::factory()->connected()->create();
    $user = User::factory()->create();

    livesOn($home, $user, '2026-08-16 09:00:00', 10, 1_000_000);

    // Exactly what a Switch leaves behind moments after it succeeds: tracked
    // on the destination, a grant issued, and no usage yet because the
    // machine has not picked it up. Offering to release this undoes the very
    // move the admin just made.
    $justGranted->users()->syncWithoutDetaching([$user->id => ['status' => MembershipStatus::Tracked->value]]);
    $device = $user->devices()->create(['device_id' => 'fingerprint-abc', 'name' => 'laptop']);
    $justGranted->provisionedGrants()->create([
        'device_id' => $device->id,
        'status' => GrantStatus::Pending,
        'provisioned_at' => now(),
    ]);

    expect(app(StaleMembershipQuery::class)->get(collect([$home, $justGranted]), RebalanceWindow::days(30)))
        ->toBe([]);

    Carbon::setTestNow();
});

it('does offer a seat granted long ago and never touched', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $home = Account::factory()->connected()->create();
    $abandoned = Account::factory()->connected()->create();
    $user = User::factory()->create();

    livesOn($home, $user, '2026-08-16 09:00:00', 10, 1_000_000);

    // Same shape, but the grant is a month old. Nobody is coming for it.
    $abandoned->users()->syncWithoutDetaching([$user->id => ['status' => MembershipStatus::Tracked->value]]);
    $device = $user->devices()->create(['device_id' => 'fingerprint-xyz', 'name' => 'old laptop']);
    $abandoned->provisionedGrants()->create([
        'device_id' => $device->id,
        'status' => GrantStatus::Pending,
        'provisioned_at' => Carbon::parse('2026-08-10 09:00:00'),
    ]);
    DB::table('account_user')->where('user_id', $user->id)->where('account_id', $abandoned->id)
        ->update(['created_at' => Carbon::parse('2026-08-10 09:00:00')]);

    expect(app(StaleMembershipQuery::class)->get(collect([$home, $abandoned]), RebalanceWindow::days(30)))
        ->toHaveCount(1);

    Carbon::setTestNow();
});
