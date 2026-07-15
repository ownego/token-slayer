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

test('the per-row connect action is hidden for an active account', function () {
    $account = Account::factory()->connected()->create();

    Livewire::actingAs($this->admin)
        ->test(ListAccounts::class)
        ->assertTableActionHidden('connect', $account);
});

test('the per-row connect action is visible for an account needing re-auth', function () {
    $account = Account::factory()->create(['status' => AccountStatus::NeedsReauth]);

    Livewire::actingAs($this->admin)
        ->test(ListAccounts::class)
        ->assertTableActionVisible('connect', $account);
});

test('re-connecting a row with a mismatched identity notifies danger and writes nothing', function () {
    fakeAnthropic();
    $account = Account::factory()->create([
        'email' => 'someone-else@example.com',
        'status' => AccountStatus::NeedsReauth,
    ]);

    Livewire::actingAs($this->admin)
        ->test(ListAccounts::class)
        ->mountTableAction('connect', $account)
        ->setTableActionData(['code' => 'pasted-code'])
        ->callMountedTableAction();

    expect($account->refresh()->oauth_access_token)->toBeNull();
});
