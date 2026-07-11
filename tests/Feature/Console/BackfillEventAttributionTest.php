<?php

use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the command attributes unrecognized events and reports the totals', function () {
    $user = User::factory()->create();
    $a = Account::factory()->withOrganizationUuid('org-a')->create();
    Event::factory()->count(2)->create(['account_id' => null, 'account_org_id' => 'org-a', 'user_id' => $user->id]);

    $this->artisan('event-attribution:backfill')
        ->expectsOutputToContain('org-a: attributed 2 events')
        ->expectsOutputToContain('total: attributed 2 events')
        ->assertSuccessful();

    expect(Event::whereNull('account_id')->where('account_org_id', 'org-a')->count())->toBe(0);
});

test('the --org option limits the backfill to one organization', function () {
    $user = User::factory()->create();
    Account::factory()->withOrganizationUuid('org-a')->create();
    Account::factory()->withOrganizationUuid('org-b')->create();
    Event::factory()->create(['account_id' => null, 'account_org_id' => 'org-a', 'user_id' => $user->id]);
    Event::factory()->create(['account_id' => null, 'account_org_id' => 'org-b', 'user_id' => $user->id]);

    $this->artisan('event-attribution:backfill', ['--org' => 'org-a'])->assertSuccessful();

    expect(Event::whereNull('account_id')->where('account_org_id', 'org-a')->count())->toBe(0)
        ->and(Event::whereNull('account_id')->where('account_org_id', 'org-b')->count())->toBe(1);
});
