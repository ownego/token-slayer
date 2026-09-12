<?php

use App\Enums\MembershipStatus;
use App\Filament\Pages\AccountRebalance;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('is forbidden for a user without the view_usage_analytics permission', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(AccountRebalance::class)->assertForbidden();
});

it('renders the recommend action results as move rows', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $admin = User::factory()->admin()->create();
    $resetAt = now()->addDays(4);

    $overflowing = Account::factory()->connected()->create();
    AccountUsageSnapshot::factory()->for($overflowing)->create(['util_7d' => 30, 'reset_7d_at' => $resetAt, 'created_at' => now()]);
    $heavy = User::factory()->create();
    Event::factory()->for($overflowing)->for($heavy)->create(['tokens' => 84_000, 'created_at' => now()->subDay()]);
    $overflowing->users()->syncWithoutDetaching([$heavy->id => ['status' => MembershipStatus::Tracked->value]]);

    $healthy = Account::factory()->connected()->create();
    AccountUsageSnapshot::factory()->for($healthy)->create(['util_7d' => 5, 'reset_7d_at' => $resetAt, 'created_at' => now()]);
    Event::factory()->for($healthy)->for(User::factory())->create(['tokens' => 10_000, 'created_at' => now()->subDays(3)]);

    Livewire::actingAs($admin)
        ->test(AccountRebalance::class)
        ->mountAction('recommend')
        ->callMountedAction()
        ->assertSet('moves.0.userId', $heavy->id);

    Carbon::setTestNow();
});
