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
    expect(collect($rows)->pluck('user_id'))->not->toContain($member->id);
});
