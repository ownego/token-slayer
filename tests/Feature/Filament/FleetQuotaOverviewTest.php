<?php

use App\Filament\Widgets\FleetQuotaOverview;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('re-probes the fleet when the refresh action is called', function (): void {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->connected()->create();

    Livewire::actingAs($admin)
        ->test(FleetQuotaOverview::class)
        ->call('refreshFleet')
        ->assertHasNoErrors();

    expect($account->usageSnapshots()->count())->toBeGreaterThan(0);
});

it('renders the refresh button in the fleet quota section header', function (): void {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(FleetQuotaOverview::class)
        ->assertSee('wire:click="refreshFleet"', false);
});

test('the gauge card lists a per-model bucket the server was never taught about', function () {
    // The account page carries this too, but a card on the dashboard is where
    // quota is actually watched. A model that did not exist when this shipped
    // has to appear here without a migration or a deploy.
    $account = Account::factory()->create(['email' => 'buckets@example.com']);
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => 10,
        'util_7d' => 20,
        'raw' => [
            'five_hour' => ['utilization' => 10],
            'seven_day' => ['utilization' => 20],
            'nimbus_quill' => ['utilization' => 73],
        ],
        'created_at' => now(),
    ]);

    Livewire::test(FleetQuotaOverview::class)
        ->assertOk()
        ->assertSee('nimbus_quill')
        ->assertSee('73%');
});

test('the gauge card does not repeat the account-wide figures as models', function () {
    $account = Account::factory()->create(['email' => 'plain@example.com']);
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => 10,
        'util_7d' => 20,
        'raw' => ['five_hour' => ['utilization' => 10], 'seven_day' => ['utilization' => 20]],
        'created_at' => now(),
    ]);

    Livewire::test(FleetQuotaOverview::class)
        ->assertOk()
        ->assertDontSee('five_hour');
});
