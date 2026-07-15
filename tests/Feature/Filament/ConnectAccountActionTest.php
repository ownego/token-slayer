<?php

use App\Enums\AccountStatus;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
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
        ->setActionData(['plan' => 'max-20x', 'name' => 'New Org'])
        ->callMountedAction();

    $account = Account::where('email', 'ongtung2212002@gmail.com')->first();
    expect($account)->not->toBeNull()
        ->and($account->plan)->toBe('max-20x')
        ->and($account->name)->toBe('New Org')
        ->and($account->status)->toBe(AccountStatus::Active);
});
