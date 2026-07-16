<?php

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows total tokens on the user list', function () {
    $admin = User::factory()->admin()->create();

    $user = User::factory()->create(['name' => 'Zed']);
    Event::factory()->for($user)->create(['tokens' => 1000, 'created_at' => now()]);
    Event::factory()->for($user)->create(['tokens' => 500, 'created_at' => now()->subDays(20)]);

    $this->actingAs($admin)
        ->get(UserResource::getUrl('index', panel: 'admin'))
        ->assertOk()
        ->assertSee('1,500') // all-time total tokens
        ->assertSee('1,000'); // windowed tokens: default range filter is 7 days, excludes the 20-day-old event
});

it('shows each user\'s windowed company-account usage, excluding unattributed and out-of-range events', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();

    $user = User::factory()->create(['name' => 'Zed']);
    Event::factory()->for($user)->create(['account_id' => $account->id, 'tokens' => 700, 'created_at' => now()]);
    Event::factory()->for($user)->create(['account_id' => null, 'tokens' => 250, 'created_at' => now()]);
    Event::factory()->for($user)->create(['account_id' => $account->id, 'tokens' => 5000, 'created_at' => now()->subDays(20)]);

    // Default range filter = last 7 days: excludes the 20-day-old account event.
    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->assertOk()
        ->assertTableColumnStateSet('total_tokens', 5950, record: $user) // all-time, all events
        ->assertTableColumnStateSet('account_tokens_in_range', 700, record: $user); // account-attributed, within 7 days
});

it('shows all-time company-account usage when the range is set to all time', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();

    $user = User::factory()->create(['name' => 'Zed']);
    Event::factory()->for($user)->create(['account_id' => $account->id, 'tokens' => 700, 'created_at' => now()]);
    Event::factory()->for($user)->create(['account_id' => $account->id, 'tokens' => 5000, 'created_at' => now()->subDays(20)]);

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->set('tableFilters.range.value', '0') // All time
        ->assertTableColumnStateSet('account_tokens_in_range', 5700, record: $user);
});

it('does not error when the tokens window filter is cleared', function () {
    $admin = User::factory()->admin()->create();

    $user = User::factory()->create(['name' => 'Zed']);
    Event::factory()->for($user)->create(['tokens' => 1000, 'created_at' => now()]);

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->filterTable('range', null)
        ->assertOk();
});
