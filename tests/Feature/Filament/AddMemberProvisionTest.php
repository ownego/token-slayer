<?php

use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Enums\Provider;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\MembersRelationManager;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\AccountUser;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('provisions the first grant onto a fresh placeholder device', function () {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $newcomer = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->setActionData(['user_id' => $newcomer->id, 'provision' => true])
        ->callMountedAction()
        ->assertActionMounted('confirmProvisionMember')
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction()
        ->assertNotified();

    $device = $newcomer->devices()->firstOrFail();
    $grant = AccountProvisionedGrant::query()
        ->where('account_id', $account->id)->where('device_id', $device->id)->firstOrFail();

    expect($device->device_id)->toBeNull()
        ->and($grant->status)->toBe(GrantStatus::Pending)
        ->and($newcomer->accounts()->find($account->id)->pivot->status)->toBe(MembershipStatus::Pending);
});

it('provisions an account for the new member when the toggle is on, landing at pending', function () {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    // Profile fixture authorizes ongtung2212002@gmail.com — the account's
    // identity must match or provisionForDevice() rejects the exchange.
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $newcomer = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->setActionData(['user_id' => $newcomer->id, 'provision' => true])
        ->callMountedAction()
        ->assertActionMounted('confirmProvisionMember')
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction()
        ->assertNotified();

    $pivot = AccountUser::query()
        ->where('user_id', $newcomer->id)->where('account_id', $account->id)->firstOrFail();
    // Provisioning audit now lives per-grant (see AccountProvisionedGrant),
    // not on the account_user pivot.
    $grant = AccountProvisionedGrant::query()
        ->where('account_id', $account->id)
        ->whereHas('device', fn ($query) => $query->where('user_id', $newcomer->id))
        ->firstOrFail();

    expect($pivot->status)->toBe(MembershipStatus::Pending)
        ->and($grant->provisioned_at)->not->toBeNull();
});

it('a failed provision (bad/expired code) leaves no orphan placeholder device or grant', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $newcomer = User::factory()->create();

    // An unstarted/expired state: exchangeVerifiedToken() throws
    // connect_state_expired before ever touching the network, so this
    // exercises the rollback without needing fakeAnthropic().
    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('confirmProvisionMember', [
            'userId' => $newcomer->id,
            'authorizeUrl' => 'https://example.test/authorize',
            'state' => 'never-started-state',
        ])
        ->setActionData(['code' => 'bad-code'])
        ->callMountedAction()
        ->assertNotified();

    expect($newcomer->devices()->count())->toBe(0)
        ->and(AccountProvisionedGrant::query()->count())->toBe(0);
});

it('shows a friendly notification instead of crashing when Anthropic rejects the pasted code', function () {
    // Anthropic's token endpoint returns 400/401 for a stale, already-used,
    // or otherwise invalid code -- AnthropicOAuthClient wraps that as
    // UsageProbeException('invalid_grant'), a different class from the
    // AccountConnectException the catch block here was written for. Before
    // this fix, that exception went uncaught and crashed the action instead
    // of leaving the admin a clear reason to retry.
    fakeAnthropic(['token' => Http::response('', 400)]);
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $newcomer = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->setActionData(['user_id' => $newcomer->id, 'provision' => true])
        ->callMountedAction()
        ->assertActionMounted('confirmProvisionMember')
        ->setActionData(['code' => 'stale-code'])
        ->callMountedAction()
        ->assertNotified('Provisioning failed');

    expect($newcomer->devices()->count())->toBe(0)
        ->and(AccountProvisionedGrant::query()->count())->toBe(0);
});

it('adds the member as tracked without provisioning when the toggle is off', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $newcomer = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->setActionData(['user_id' => $newcomer->id, 'provision' => false])
        ->callMountedAction()
        ->assertNotified()
        ->assertActionNotMounted('confirmProvisionMember');

    $pivot = AccountUser::query()
        ->where('user_id', $newcomer->id)->where('account_id', $account->id)->firstOrFail();

    expect($pivot->status)->toBe(MembershipStatus::Tracked)
        // Grant-layer equivalent of "without provisioning": no device or
        // grant row was created for the newcomer at all.
        ->and($newcomer->devices()->doesntExist())->toBeTrue()
        ->and(AccountProvisionedGrant::query()->whereHas('device', fn ($query) => $query->where('user_id', $newcomer->id))->doesntExist())->toBeTrue();
});

it('a zero-device user only sees the device name field, and naming it creates a named placeholder with pending membership', function () {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $newcomer = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->setActionData(['user_id' => $newcomer->id, 'provision' => true])
        ->assertSchemaComponentHidden('device_pk')
        ->assertSchemaComponentVisible('device_name')
        ->setActionData(['user_id' => $newcomer->id, 'provision' => true, 'device_name' => 'Newcomer Laptop'])
        ->callMountedAction()
        ->assertActionMounted('confirmProvisionMember')
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction()
        ->assertNotified();

    $device = $newcomer->devices()->firstOrFail();
    $grant = AccountProvisionedGrant::query()
        ->where('account_id', $account->id)->where('device_id', $device->id)->firstOrFail();

    expect($device->device_id)->toBeNull()
        ->and($device->name)->toBe('Newcomer Laptop')
        ->and($grant->status)->toBe(GrantStatus::Pending)
        ->and($newcomer->accounts()->find($account->id)->pivot->status)->toBe(MembershipStatus::Pending);
});

it('a user with devices sees the device select offering existing devices plus New device, and choosing one provisions onto it', function () {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $member = User::factory()->create();
    $existingDevice = Device::factory()->for($member)->create(['device_id' => 'fp-existing', 'name' => 'Alice Laptop']);

    $livewire = Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->setActionData(['user_id' => $member->id, 'provision' => true])
        ->assertSchemaComponentVisible('device_pk');

    $schemaName = $livewire->instance()->getMountedActionSchemaName();
    $devicePk = $livewire->instance()->{$schemaName}->getFlatComponents(withHidden: true)['device_pk'];

    expect($devicePk->getOptions())->toBe([$existingDevice->id => 'Alice Laptop'])
        ->and($devicePk->getPlaceholder())->toBe('+ Create a new device…');

    $livewire
        ->setActionData(['user_id' => $member->id, 'provision' => true, 'device_pk' => $existingDevice->id])
        ->callMountedAction()
        ->assertActionMounted('confirmProvisionMember')
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction()
        ->assertNotified();

    $grant = AccountProvisionedGrant::query()
        ->where('account_id', $account->id)->where('device_id', $existingDevice->id)->firstOrFail();

    expect($member->devices()->count())->toBe(1)
        ->and($grant->status)->toBe(GrantStatus::Pending);
});

it('keeps the device name field hidden until a user is selected', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->assertSchemaComponentHidden('device_name');
});

it('hides the device name field once an existing device is selected', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $member = User::factory()->create();
    $existingDevice = Device::factory()->for($member)->create(['device_id' => 'fp-existing', 'name' => 'Alice Laptop']);

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->setActionData(['user_id' => $member->id, 'provision' => true])
        ->assertSchemaComponentVisible('device_name')
        ->setActionData(['user_id' => $member->id, 'provision' => true, 'device_pk' => $existingDevice->id])
        ->assertSchemaComponentHidden('device_name');
});

it('shows the device name field again once the device select is cleared back to "create a new device"', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $member = User::factory()->create();
    $existingDevice = Device::factory()->for($member)->create(['device_id' => 'fp-existing']);

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->setActionData(['user_id' => $member->id, 'provision' => true, 'device_pk' => $existingDevice->id])
        ->assertSchemaComponentHidden('device_name')
        ->setActionData(['user_id' => $member->id, 'provision' => true, 'device_pk' => null])
        ->assertSchemaComponentVisible('device_name');
});

it('blocks opening the PKCE modal when the selected user already has a placeholder awaiting a machine', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $member = User::factory()->create();
    Device::factory()->for($member)->placeholder()->create();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->setActionData(['user_id' => $member->id, 'provision' => true, 'device_pk' => null])
        ->callMountedAction()
        ->assertNotified()                       // the danger "already awaiting" notification
        ->assertActionNotMounted('confirmProvisionMember');

    expect($member->devices()->whereNull('device_id')->count())->toBe(1);
});

it('a tracked member getting a new device keeps their Tracked membership instead of being demoted to pending', function () {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $member = User::factory()->create();
    $account->users()->syncWithoutDetaching([$member->id => ['status' => MembershipStatus::Tracked->value]]);

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->setActionData(['user_id' => $member->id, 'provision' => true, 'device_name' => 'Second Laptop'])
        ->callMountedAction()
        ->assertActionMounted('confirmProvisionMember')
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction()
        ->assertNotified();

    expect($account->users()->find($member->id)->pivot->status)->toBe(MembershipStatus::Tracked);
});

it('a pending member getting a second device stays pending instead of being silently promoted to tracked', function () {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $member = User::factory()->create();
    $account->users()->syncWithoutDetaching([$member->id => ['status' => MembershipStatus::Pending->value]]);

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->setActionData(['user_id' => $member->id, 'provision' => true, 'device_name' => 'Second Laptop'])
        ->callMountedAction()
        ->assertActionMounted('confirmProvisionMember')
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction()
        ->assertNotified();

    expect($account->users()->find($member->id)->pivot->status)->toBe(MembershipStatus::Pending);
});

it('hides the provision toggle on a Codex account and shows the CLI command to run instead', function () {
    // Codex device provisioning has no in-UI equivalent -- it happens via
    // `token-slayer admin codex-provision`, driven from an admin's own
    // machine (see CodexProvisioningService's docblock). Claude's
    // PKCE-paste flow this toggle drives is meaningless here, so the
    // toggle must stay hidden and default off for a Codex account.
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['provider' => Provider::Codex, 'name' => 'Shared Codex Bot']);
    $newcomer = User::factory()->create(['email' => 'newcomer@example.test']);
    $another = User::factory()->create(['email' => 'another@example.test']);

    $livewire = Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->assertSchemaComponentHidden('provision')
        ->setActionData(['user_id' => $newcomer->id]);

    $schemaName = $livewire->instance()->getMountedActionSchemaName();
    $hint = fn () => $livewire->instance()->{$schemaName}->getFlatComponents(withHidden: true)['codex_provision_hint'];

    expect($hint()->isCopyable())->toBeTrue()
        ->and($hint()->getState())->toBe('token-slayer admin codex-provision "Shared Codex Bot" --for newcomer@example.test');

    // Reactive: swapping the selected user updates the command in place,
    // not just at initial mount.
    $livewire->setActionData(['user_id' => $another->id]);
    expect($hint()->getState())->toBe('token-slayer admin codex-provision "Shared Codex Bot" --for another@example.test');

    $livewire->setActionData(['user_id' => $newcomer->id])
        ->callMountedAction()->assertNotified()->assertActionNotMounted('confirmProvisionMember');

    $pivot = AccountUser::query()->where('user_id', $newcomer->id)->where('account_id', $account->id)->firstOrFail();

    expect($pivot->status)->toBe(MembershipStatus::Tracked)
        ->and($newcomer->devices()->doesntExist())->toBeTrue();
});

it('keeps the provision toggle visible and hides the Codex hint for a Claude account', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addMember')
        ->assertSchemaComponentVisible('provision')
        ->assertSchemaComponentHidden('codex_provision_hint');
});
