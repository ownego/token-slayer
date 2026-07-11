<?php

use App\Enums\AccountStatus;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\RelationManagers\UsersRelationManager;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('blocks non-admins from the panel', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->get('/admin')->assertForbidden();
});

it('lets an admin into the panel dashboard', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/admin')->assertOk();
});

it('lets an admin create and list accounts', function () {
    $admin = User::factory()->create(['is_admin' => true]);

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
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(CreateAccount::class)
        ->fillForm(['email' => 'dupe@ownego.com', 'plan' => 'max-20x'])
        ->call('create')
        ->assertHasFormErrors(['email']);
});

it('lists accounts with member count and status badge', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->needsReauth()->create(['email' => 'org@ownego.com']);
    $account->users()->attach(User::factory()->count(2)->create());

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$account])
        ->assertTableColumnStateSet('users_count', 2, $account)
        ->assertTableColumnStateSet('status', AccountStatus::NeedsReauth, $account);
});

it('lets an admin set the organization uuid when editing an account', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->create(['organization_uuid' => null]);

    Livewire::actingAs($admin)
        ->test(EditAccount::class, ['record' => $account->getRouteKey()])
        ->fillForm(['organization_uuid' => 'org-12345'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->refresh()->organization_uuid)->toBe('org-12345');
});

it('attaches and detaches members through the relation manager', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->create();
    $user = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(UsersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->callTableAction('attach', data: ['recordId' => $user->id]);

    expect($account->users()->whereKey($user->id)->exists())->toBeTrue();

    Livewire::actingAs($admin)
        ->test(UsersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->callTableAction('detach', record: $user);

    expect($account->users()->whereKey($user->id)->exists())->toBeFalse();
});

it('shows the connect action to an admin and mounts a fresh authorize url', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->create();

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->assertTableActionExists('connect', record: $account)
        ->mountTableAction('connect', $account)
        ->assertTableActionDataSet(fn (array $state) => str_starts_with($state['authorize_url'], 'https://claude.com/cai/oauth/authorize')
            && filled($state['state']));
});

it('completes the connect action and marks the account active', function () {
    fakeAnthropic();
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->callTableAction('connect', $account, data: ['code' => 'the-pasted-code'])
        ->assertNotified();

    expect($account->refresh()->status)->toBe(AccountStatus::Active)
        ->and($account->oauth_access_token)->not->toBeNull();
});

it('notifies a friendly error on connect email mismatch and stores nothing', function () {
    fakeAnthropic();
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->create(['email' => 'mismatched@example.com']);

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->callTableAction('connect', $account, data: ['code' => 'the-pasted-code'])
        ->assertNotified();

    expect($account->refresh()->oauth_access_token)->toBeNull();
});

it('notifies a friendly error when the connect state has expired', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->create();

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
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->connected()->create();

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->callTableAction('refreshNow', $account)
        ->assertNotified();

    expect($account->usageSnapshots()->count())->toBe(1)
        ->and($account->refresh()->last_probed_at)->not->toBeNull();
});

it('disconnects an account by wiping its stored tokens', function () {
    $admin = User::factory()->create(['is_admin' => true]);
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
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->connected()->create();
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => 12, 'util_7d' => 34, 'created_at' => now()->subMinute(),
    ]);

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->assertTableColumnStateSet('latestUsageSnapshot.util_5h', 12, $account)
        ->assertTableColumnStateSet('latestUsageSnapshot.util_7d', 34, $account);
});
