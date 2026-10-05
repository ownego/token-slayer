<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\Device;
use App\Models\User;
use App\Services\Provisioning\AccountDevicesQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists live grants of tracked and pending members, overdue and soonest first, pending set-ups last', function (): void {
    $account = Account::factory()->create();
    $tracked = User::factory()->create();
    $pendingMember = User::factory()->create();
    $untracked = User::factory()->create();
    $account->users()->attach([
        $tracked->id => ['status' => MembershipStatus::Tracked->value],
        $pendingMember->id => ['status' => MembershipStatus::Pending->value],
        $untracked->id => ['status' => MembershipStatus::Untracked->value],
    ]);
    $grantFor = fn (User $user) => AccountProvisionedGrant::factory()->for($account)->for(Device::factory()->for($user));

    $unknown = $grantFor($tracked)->claimed()->create(['session_expires_at' => null]);
    $later = $grantFor($tracked)->claimed()->create(['session_expires_at' => now()->addDays(10)]);
    $overdue = $grantFor($pendingMember)->claimed()->create(['session_expires_at' => now()->subDays(2)]);
    $notSetUp = $grantFor($pendingMember)->pending()->create();
    $grantFor($tracked)->revoked()->create();
    $grantFor($untracked)->claimed()->create(['session_expires_at' => now()->addDay()]);
    AccountProvisionedGrant::factory()->claimed()->create();

    expect(app(AccountDevicesQuery::class)->forAccount($account)->pluck('id')->all())
        ->toBe([$overdue->id, $later->id, $unknown->id, $notSetUp->id]);
});
