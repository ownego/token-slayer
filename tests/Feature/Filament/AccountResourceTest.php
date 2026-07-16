<?php

use App\Enums\AccountStatus;
use App\Enums\MembershipStatus;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\RelationManagers\MembersRelationManager;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('blocks non-admins from the panel', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')->assertForbidden();
});

it('lets an admin into the panel dashboard', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')->assertOk();
});

it('lets an admin create and list accounts', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(CreateAccount::class)
        ->fillForm(['email' => 'new@ownego.com', 'plan' => 'max-20x'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Account::where('email', 'new@ownego.com')->exists())->toBeTrue();
});

it('rejects a duplicate account email on create', function () {
    Account::factory()->create(['email' => 'dupe@ownego.com']);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(CreateAccount::class)
        ->fillForm(['email' => 'dupe@ownego.com', 'plan' => 'max-20x'])
        ->call('create')
        ->assertHasFormErrors(['email']);
});

it('lists accounts with member count and status badge', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->needsReauth()->create(['email' => 'org@ownego.com']);
    $account->users()->attach(User::factory()->count(2)->create());

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$account])
        ->assertTableColumnStateSet('tracked_users_count', 2, $account)
        ->assertTableColumnStateSet('status', AccountStatus::NeedsReauth, $account);
});

it('lets an admin set the organization uuid when creating an account', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(CreateAccount::class)
        ->fillForm(['email' => 'uuid-on-create@ownego.com', 'plan' => 'max-20x', 'organization_uuid' => 'org-12345'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Account::where('email', 'uuid-on-create@ownego.com')->first()->organization_uuid)->toBe('org-12345');
});

it('makes email, organization uuid, and plan read-only when editing an account', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();

    Livewire::actingAs($admin)
        ->test(EditAccount::class, ['record' => $account->getRouteKey()])
        ->assertFormFieldDisabled('email')
        ->assertFormFieldDisabled('organization_uuid')
        ->assertFormFieldDisabled('plan')
        ->assertFormFieldEnabled('name')
        ->assertFormFieldEnabled('status');
});

it('leaves the organization uuid unchanged when an admin attempts to edit it', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['organization_uuid' => 'original-uuid']);

    Livewire::actingAs($admin)
        ->test(EditAccount::class, ['record' => $account->getRouteKey()])
        ->fillForm(['organization_uuid' => 'attempted-change'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->refresh()->organization_uuid)->toBe('original-uuid');
});

it('demotes a tracked member to untracked through the relation manager', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user, ['status' => MembershipStatus::Tracked->value]);

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->callTableAction('unverify', record: $user);

    expect($account->trackedUsers()->whereKey($user->id)->exists())->toBeFalse();
    expect($account->untrackedUsers()->whereKey($user->id)->exists())->toBeTrue();
});

it('shows the connect action to an admin and mounts a fresh authorize url', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['status' => AccountStatus::NeedsReauth]);

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->assertTableActionExists('connect', record: $account)
        ->mountTableAction('connect', $account)
        ->assertTableActionDataSet(fn (array $state) => str_starts_with($state['authorize_url'], 'https://claude.com/cai/oauth/authorize')
            && filled($state['state']));
});

it('completes the connect action and marks the account active', function () {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com', 'status' => AccountStatus::NeedsReauth]);

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->callTableAction('connect', $account, data: ['code' => 'the-pasted-code'])
        ->assertNotified();

    expect($account->refresh()->status)->toBe(AccountStatus::Active)
        ->and($account->oauth_access_token)->not->toBeNull();
});

it('notifies a friendly error on connect identity mismatch and stores nothing', function () {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['email' => 'mismatched@example.com', 'status' => AccountStatus::NeedsReauth]);

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->callTableAction('connect', $account, data: ['code' => 'the-pasted-code'])
        ->assertNotified();

    expect($account->refresh()->oauth_access_token)->toBeNull();
});

it('notifies a friendly error when the connect state has expired', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['status' => AccountStatus::NeedsReauth]);

    $component = Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->mountTableAction('connect', $account)
        ->setTableActionData(['code' => 'the-pasted-code']);

    Cache::flush();

    $component->callMountedTableAction()
        ->assertNotified();
});

it('refreshes usage on demand and records a snapshot', function () {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->connected()->create();

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->callTableAction('refreshNow', $account)
        ->assertNotified();

    expect($account->usageSnapshots()->count())->toBe(1)
        ->and($account->refresh()->last_probed_at)->not->toBeNull();
});

it('disconnects an account by wiping its stored tokens', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->connected()->create();

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->callTableAction('disconnect', $account)
        ->assertNotified();

    $account->refresh();

    expect($account->oauth_access_token)->toBeNull()
        ->and($account->oauth_refresh_token)->toBeNull()
        ->and($account->status)->toBe(AccountStatus::NeedsReauth);
});

it('shows the latest usage utilization in the account table', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->connected()->create();
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => 12, 'util_7d' => 34, 'created_at' => now()->subMinute(),
    ]);

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->assertTableColumnStateSet('latestUsageSnapshot.util_5h', 12, $account)
        ->assertTableColumnStateSet('latestUsageSnapshot.util_7d', 34, $account);
});
