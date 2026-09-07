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

test('the gauge card lists a per-model limit under the name the API gives it', function () {
    // The account page carries this too, but a card on the dashboard is where
    // quota is actually watched. A model that did not exist when this shipped
    // has to appear here without a migration or a deploy.
    $account = Account::factory()->create(['email' => 'buckets@example.com']);
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => 10,
        'util_7d' => 20,
        'raw' => ['limits' => [
            ['kind' => 'session', 'percent' => 10, 'scope' => null],
            ['kind' => 'weekly_all', 'percent' => 20, 'scope' => null],
            ['kind' => 'weekly_scoped', 'percent' => 73,
                'scope' => ['model' => ['id' => null, 'display_name' => 'Fable']]],
        ]],
        'created_at' => now(),
    ]);

    Livewire::test(FleetQuotaOverview::class)
        ->assertOk()
        ->assertSee('Fable')
        ->assertSee('73%');
});

test('the gauge card does not repeat the account-wide figures as models', function () {
    $account = Account::factory()->create(['email' => 'plain@example.com']);
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => 10,
        'util_7d' => 20,
        'raw' => ['limits' => [
            ['kind' => 'session', 'percent' => 10, 'scope' => null],
            ['kind' => 'weekly_all', 'percent' => 20, 'scope' => null],
        ]],
        'created_at' => now(),
    ]);

    Livewire::test(FleetQuotaOverview::class)
        ->assertOk()
        ->assertDontSee('weekly_all');
});

test('the gauge card labels a Codex window by the duration it actually reports', function () {
    // A free-tier Codex account reports one 30-day cap. Showing it under the
    // card's 5H row would put a month's usage behind an hour's name.
    $account = Account::factory()->create(['email' => 'codex@example.com', 'provider' => 'codex']);
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => null,
        'util_7d' => null,
        'raw' => ['rate_limit' => [
            'primary_window' => ['used_percent' => 4, 'limit_window_seconds' => 2592000],
            'secondary_window' => null,
        ]],
        'created_at' => now(),
    ]);

    Livewire::test(FleetQuotaOverview::class)
        ->assertOk()
        ->assertSee('30D')
        ->assertSee('4%');
});
