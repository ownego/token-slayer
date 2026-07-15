<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\User;
use App\Services\Attribution\UnattachedUsersQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns only users with no account membership, newest active first', function () {
    $account = Account::factory()->create();

    $member = User::factory()->create(['name' => 'Member', 'last_event_at' => now()]);
    $account->users()->attach($member, ['status' => MembershipStatus::Untracked->value]);

    $older = User::factory()->create(['name' => 'Older', 'slack_handle' => null, 'display_name' => null, 'last_event_at' => now()->subDay()]);
    $newer = User::factory()->create(['name' => 'Newer', 'slack_handle' => 'newer.handle', 'last_event_at' => now()->subHour()]);

    $rows = app(UnattachedUsersQuery::class)->get();

    expect(collect($rows)->pluck('user_id')->all())->toBe([$newer->id, $older->id]);
    expect($rows[0]['handle'])->toBe('newer.handle');
    expect($rows[1]['handle'])->toBe('Older');
    expect($rows[0]['email'])->toBe($newer->email);
    expect(collect($rows)->pluck('user_id'))->not->toContain($member->id);
});

it('orders users with no recent activity last', function () {
    $active = User::factory()->create(['name' => 'Active', 'last_event_at' => now()->subDay(), 'created_at' => now()->subWeek()]);
    $neverActive = User::factory()->create(['name' => 'NeverActive', 'last_event_at' => null, 'created_at' => now()]);

    $rows = app(UnattachedUsersQuery::class)->get();

    expect(collect($rows)->pluck('user_id')->all())->toBe([$active->id, $neverActive->id]);
    expect($rows[1]['last_event_at'])->toBeNull();
});

it('breaks a last_event_at tie by newest created_at first', function () {
    $sameActivity = now()->subHour();

    $olderAccount = User::factory()->create(['name' => 'OlderAccount', 'last_event_at' => $sameActivity, 'created_at' => now()->subWeek()]);
    $newerAccount = User::factory()->create(['name' => 'NewerAccount', 'last_event_at' => $sameActivity, 'created_at' => now()]);

    $rows = app(UnattachedUsersQuery::class)->get();

    expect(collect($rows)->pluck('user_id')->all())->toBe([$newerAccount->id, $olderAccount->id]);
});

it('breaks a nulled last_event_at tie by newest created_at first', function () {
    $olderNeverActive = User::factory()->create(['name' => 'OlderNeverActive', 'last_event_at' => null, 'created_at' => now()->subWeek()]);
    $newerNeverActive = User::factory()->create(['name' => 'NewerNeverActive', 'last_event_at' => null, 'created_at' => now()]);

    $rows = app(UnattachedUsersQuery::class)->get();

    expect(collect($rows)->pluck('user_id')->all())->toBe([$newerNeverActive->id, $olderNeverActive->id]);
});
