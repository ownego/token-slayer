<?php

use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists a per-model bucket the server was never taught about', function () {
    // The whole point: a bucket that did not exist when this shipped is
    // visible without a migration or a deploy.
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => 10,
        'util_7d' => 20,
        'raw' => [
            'five_hour' => ['utilization' => 10],
            'seven_day' => ['utilization' => 20],
            'nimbus_quill' => ['utilization' => 73],
        ],
    ]);

    Livewire::actingAs($admin)->test(ViewAccount::class, ['record' => $account->id])
        ->assertOk()
        ->assertSee('nimbus_quill')
        ->assertSee('73%');
});

it('does not repeat the account-wide figures as if they were models', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => 10,
        'util_7d' => 20,
        'raw' => ['five_hour' => ['utilization' => 10], 'seven_day' => ['utilization' => 20]],
    ]);

    Livewire::actingAs($admin)->test(ViewAccount::class, ['record' => $account->id])
        ->assertOk()
        ->assertDontSee('five_hour');
});
