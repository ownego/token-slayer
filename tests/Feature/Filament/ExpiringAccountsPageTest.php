<?php

use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Filament\Pages\ExpiringAccounts;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\AccountReserveToken;
use App\Models\ClaudeCredential;
use App\Models\CodexCredential;
use App\Models\Device;
use App\Models\User;
use App\Services\AccountConnectService;
use App\Services\Connect\ConnectResolution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('the page loads for an admin and lists an expiring account', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::create(['email' => 'soon@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDay()]);

    Livewire::actingAs($admin)->test(ExpiringAccounts::class)
        ->assertOk()
        ->assertSee('soon@example.com');
});

it('is forbidden for a user without the view_usage_analytics permission', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(ExpiringAccounts::class)->assertForbidden();
});

it('offers a reconnect action on a Claude row so the admin never has to open the account', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::create(['email' => 'soon@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDay()]);

    Livewire::actingAs($admin)->test(ExpiringAccounts::class)
        ->assertActionExists('reconnectAccount')
        ->assertSee('Reconnect');
});

it('completes a reconnect against the account named by the row, not whatever was authorized', function (): void {
    // The whole point of binding the button to a row: the admin clicked THIS
    // account, so authorizing a different one has to be rejected rather than
    // silently repairing someone else's credentials.
    $admin = User::factory()->admin()->create();
    $account = Account::create(['email' => 'soon@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDay()]);

    $service = Mockery::mock(AccountConnectService::class);
    $service->shouldReceive('start')->andReturn(['url' => 'https://example.test/auth', 'state' => 'st4te']);
    $service->shouldReceive('resolve')
        ->once()
        ->withArgs(fn (string $state, string $code, Account $expected): bool => $expected->is($account))
        ->andReturn(ConnectResolution::existing($account));
    app()->instance(AccountConnectService::class, $service);

    Livewire::actingAs($admin)->test(ExpiringAccounts::class)
        ->mountAction('reconnectAccount', ['account' => $account->id])
        ->setActionData(['state' => 'st4te', 'code' => 'pasted-code'])
        ->callMountedAction()
        ->assertHasNoActionErrors();
});

it('offers a usage refresh on a Codex row instead of a reconnect', function (): void {
    // Codex has no per-row reconnect — its connect flow is device-code and
    // binds to whoever approves, not to the row. A re-probe is the repair
    // that can honestly be aimed at one account.
    $admin = User::factory()->admin()->create();
    $account = Account::create(['email' => 'stale@example.com', 'provider' => 'codex']);
    CodexCredential::create([
        'account_id' => $account->id,
        'last_refreshed_at' => now()->subDays(30),
    ]);

    Livewire::actingAs($admin)->test(ExpiringAccounts::class)
        ->assertOk()
        ->assertSee('stale@example.com')
        ->assertActionExists('refreshAccountUsage');
});

it('splits the sidebar badge into a green pending count and a red unhandled count', function (): void {
    // Two accounts on the Expiring list: one already has a fresh grant out
    // (the admin's own worry from being unable to tell), one has nothing.
    $handled = Account::create(['email' => 'handled@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $handled->id, 'oauth_refresh_expires_at' => now()->addDay()]);
    AccountProvisionedGrant::factory()->for($handled)->pending()
        ->create(['provisioned_at' => now()->subHour()]);

    $untouched = Account::create(['email' => 'untouched@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $untouched->id, 'oauth_refresh_expires_at' => now()->addDay()]);

    expect(ExpiringAccounts::getNavigationBadge())->toBe('🟢1 pending  🔴1 unhandled');
});

it('omits a zero half of the badge instead of showing it empty', function (): void {
    $handled = Account::create(['email' => 'handled@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $handled->id, 'oauth_refresh_expires_at' => now()->addDay()]);
    AccountProvisionedGrant::factory()->for($handled)->pending()
        ->create(['provisioned_at' => now()->subHour()]);

    expect(ExpiringAccounts::getNavigationBadge())->toBe('🟢1 pending');
});

it('shows no sidebar badge at all when nothing is expiring', function (): void {
    expect(ExpiringAccounts::getNavigationBadge())->toBeNull();
});

it('spells out each bucket in the badge tooltip', function (): void {
    $handled = Account::create(['email' => 'handled@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $handled->id, 'oauth_refresh_expires_at' => now()->addDay()]);
    AccountProvisionedGrant::factory()->for($handled)->pending()
        ->create(['provisioned_at' => now()->subHour()]);

    $untouched = Account::create(['email' => 'untouched@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $untouched->id, 'oauth_refresh_expires_at' => now()->addDay()]);

    $tooltip = ExpiringAccounts::getNavigationBadgeTooltip();

    expect($tooltip)->toContain('pending')->toContain('awaiting pull')
        ->and($tooltip)->toContain('unhandled')->toContain('no live grant');
});

it('shows a green pending badge on a row that already has a fresh grant', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::create(['email' => 'handled@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDay()]);
    AccountProvisionedGrant::factory()->for($account)->pending()
        ->create(['provisioned_at' => now()->subHour()]);

    Livewire::actingAs($admin)->test(ExpiringAccounts::class)
        ->assertOk()
        ->assertSee('🟢')
        ->assertSee('pending');
});

it('shows a red unhandled badge on a row with no live grant', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::create(['email' => 'untouched@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDay()]);

    Livewire::actingAs($admin)->test(ExpiringAccounts::class)
        ->assertOk()
        ->assertSee('🔴');
});

it('lists a member own expiring session even though the account credential is healthy, with a Reissue for that device', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::create(['email' => 'shared@example.com', 'provider' => 'claude']);
    ClaudeCredential::create([
        'account_id' => $account->id,
        'oauth_refresh_expires_at' => now()->addDays(20),
        'last_refreshed_at' => now()->subHour(),
    ]);
    $member = User::factory()->create(['email' => 'member@example.com']);
    $device = Device::factory()->for($member)->create();
    $account->users()->syncWithoutDetaching([$member->id => ['status' => MembershipStatus::Tracked->value]]);
    AccountProvisionedGrant::factory()->for($account)->for($device)->claimed()->create([
        'session_expires_at' => now()->addDay(),
    ]);

    Livewire::actingAs($admin)->test(ExpiringAccounts::class)
        ->assertOk()
        ->assertSee('member@example.com')
        ->assertSee('Reissue')
        ->assertDontSee(AccountResource::getUrl('edit', ['record' => $account->id], panel: 'admin'), escape: false);
});

it('reissues a due device straight from the Expiring page', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::create(['email' => 'shared@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(20)]);
    $member = User::factory()->create(['email' => 'bob@example.com']);
    $account->users()->attach($member->id, ['status' => MembershipStatus::Tracked->value]);
    $grant = AccountProvisionedGrant::factory()->for($account)->for(Device::factory()->for($member))->claimed()->create(['session_expires_at' => now()->addDay()->addHour()]);
    $reserve = AccountReserveToken::factory()->for($account)->create();

    Livewire::actingAs($admin)->test(ExpiringAccounts::class)
        ->assertSee('bob@example.com')
        ->assertSee('1d left')
        ->assertDontSee('Open account')
        ->mountAction('reissueGrant', ['grant' => $grant->id])
        ->assertActionMounted('confirmReissue')
        ->callMountedAction()
        ->assertNotified('Grant reissued');

    expect($reserve->fresh()->used_at)->not->toBeNull();
});

it('reveals healthy accounts only when Show all is on', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::create(['email' => 'healthy@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(20)]);

    Livewire::actingAs($admin)->test(ExpiringAccounts::class)
        ->assertDontSee('healthy@example.com')
        ->set('showAll', true)
        ->assertSee('healthy@example.com');
});

it('does not let an analytics-only viewer reissue a member grant', function (): void {
    Permission::firstOrCreate(['name' => 'view_usage_analytics', 'guard_name' => 'web']);
    Role::create(['name' => 'usage_viewer', 'guard_name' => 'web'])->givePermissionTo('view_usage_analytics');
    $viewer = User::factory()->create();
    $viewer->assignRole('usage_viewer');
    $account = Account::create(['email' => 'shared@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(20)]);
    $member = User::factory()->create();
    $account->users()->attach($member->id, ['status' => MembershipStatus::Tracked->value]);
    $grant = AccountProvisionedGrant::factory()->for($account)->for(Device::factory()->for($member))->claimed()->create(['session_expires_at' => now()->addDay()]);
    $reserve = AccountReserveToken::factory()->for($account)->create();

    Livewire::actingAs($viewer)->test(ExpiringAccounts::class)
        ->assertActionHidden('reissueGrant', ['grant' => $grant->id])
        ->mountAction('confirmReissue', ['grantId' => $grant->id, 'authorizeUrl' => '', 'state' => ''])
        ->callMountedAction();

    expect($grant->fresh()->status)->toBe(GrantStatus::Claimed)
        ->and($reserve->fresh()->used_at)->toBeNull();
});
