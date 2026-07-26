<?php

use App\Filament\Widgets\FleetQuotaOverview;
use App\Models\Account;
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
