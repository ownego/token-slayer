<?php

use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Services\Provisioning\ClaimedGrantsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists only claimed grants of the account, overdue and soonest first, unknown deadlines last', function (): void {
    $account = Account::factory()->create();
    $unknown = AccountProvisionedGrant::factory()->for($account)->claimed()->create(['session_expires_at' => null]);
    $later = AccountProvisionedGrant::factory()->for($account)->claimed()->create(['session_expires_at' => now()->addDays(10)]);
    $overdue = AccountProvisionedGrant::factory()->for($account)->claimed()->create(['session_expires_at' => now()->subDays(2)]);
    AccountProvisionedGrant::factory()->for($account)->pending()->create();
    AccountProvisionedGrant::factory()->for($account)->revoked()->create();
    AccountProvisionedGrant::factory()->claimed()->create();

    expect(app(ClaimedGrantsQuery::class)->forAccount($account)->pluck('id')->all())
        ->toBe([$overdue->id, $later->id, $unknown->id]);
});
