<?php

use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists a per-model limit under the name the API gives it', function () {
    // The whole point: a bucket that did not exist when this shipped is
    // visible without a migration or a deploy.
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => 10,
        'util_7d' => 20,
        'raw' => ['limits' => [
            ['kind' => 'session', 'percent' => 10, 'scope' => null],
            ['kind' => 'weekly_all', 'percent' => 20, 'scope' => null],
            ['kind' => 'weekly_scoped', 'percent' => 73,
                'scope' => ['model' => ['id' => null, 'display_name' => 'Fable']]],
        ]],
    ]);

    Livewire::actingAs($admin)->test(ViewAccount::class, ['record' => $account->id])
        ->assertOk()
        ->assertSee('Fable')
        ->assertSee('73%');
});

it('does not repeat the account-wide figures as if they were models', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => 10,
        'util_7d' => 20,
        'raw' => ['limits' => [
            ['kind' => 'session', 'percent' => 10, 'scope' => null],
            ['kind' => 'weekly_all', 'percent' => 20, 'scope' => null],
        ]],
    ]);

    Livewire::actingAs($admin)->test(ViewAccount::class, ['record' => $account->id])
        ->assertOk()
        ->assertDontSee('weekly_all');
});
