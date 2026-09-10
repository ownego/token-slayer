<?php

use App\Enums\AccountPlan;
use App\Enums\AccountStatus;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('labels the header action "Connect Claude account", not the ambiguous "Connect account"', function () {
    // Renamed for symmetry with "Connect Codex account" — the old label
    // read as provider-neutral even though the flow behind it is
    // Claude-only PKCE, which was confusing next to the Codex button.
    Livewire::actingAs($this->admin)
        ->test(ListAccounts::class)
        ->assertSeeText('Connect Claude account')
        ->assertDontSeeText('Connect account');
});

test('connecting an existing identity updates its token and does not open the create modal', function () {
    fakeAnthropic();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com', 'status' => AccountStatus::NeedsReauth]);

    Livewire::actingAs($this->admin)
        ->test(ListAccounts::class)
        ->mountAction('connectAccount')
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction()
        ->assertActionNotMounted('confirmCreateAccount');

    expect($account->refresh()->oauth_access_token)->toBe('sk-ant-oat01-REDACTED');
});

test('connecting a brand-new identity opens the confirm-create modal, and confirming creates the account', function () {
    fakeAnthropic();

    Livewire::actingAs($this->admin)
        ->test(ListAccounts::class)
        ->mountAction('connectAccount')
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction()
        ->assertActionMounted('confirmCreateAccount')
        ->setActionData(['plan' => AccountPlan::Max20x->value, 'name' => 'New Org'])
        ->callMountedAction();

    $account = Account::where('email', 'ongtung2212002@gmail.com')->first();
    expect($account)->not->toBeNull()
        ->and($account->plan)->toBe(AccountPlan::Max20x)
        ->and($account->name)->toBe('New Org')
        ->and($account->status)->toBe(AccountStatus::Active);
});

test('shows a friendly notification instead of crashing when Anthropic rejects the pasted code', function () {
    // Same UsageProbeException('invalid_grant') gap as the Members
    // add-member flow: a stale, already-used, or otherwise invalid code
    // returns 400/401 from Anthropic's token endpoint, which used to go
    // uncaught here too.
    fakeAnthropic(['token' => Http::response('', 400)]);

    Livewire::actingAs($this->admin)
        ->test(ListAccounts::class)
        ->mountAction('connectAccount')
        ->setActionData(['code' => 'stale-code'])
        ->callMountedAction()
        ->assertNotified('Connect failed')
        ->assertActionNotMounted('confirmCreateAccount');
});
