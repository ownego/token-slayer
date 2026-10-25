<?php

use App\Enums\MembershipStatus;
use App\Filament\Widgets\AccountQuotaHistoryChart;
use App\Filament\Widgets\ActivityHeatmap;
use App\Filament\Widgets\FleetQuotaOverview;
use App\Filament\Widgets\TokenVolumeChart;
use App\Filament\Widgets\TopAccountsLeaderboard;
use App\Filament\Widgets\TopUsersLeaderboard;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the token volume chart renders with data for the default range', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['provider' => 'claude-code', 'tokens' => 500, 'created_at' => now()->subDay()]);

    Livewire::test(TokenVolumeChart::class, ['filters' => ['range' => '7d']])
        ->assertOk();
});

test('the token volume chart colors the claude-ai dataset by its real stored provider value', function () {
    // events.provider stores "claude-ai" (hyphen) -- verified against real
    // production data and the tracker userscript's own ?provider= query
    // param. PROVIDER_COLORS used to be keyed "claude.ai" (dot), a display
    // label that never matches the stored value, so the lookup silently fell
    // through to the generic gray fallback for every claude-ai event.
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['provider' => 'claude-ai', 'tokens' => 500, 'created_at' => now()->subDay()]);

    $component = Livewire::test(TokenVolumeChart::class, ['filters' => ['range' => '7d']]);
    $getData = new ReflectionMethod($component->instance(), 'getData');
    $getData->setAccessible(true);
    $data = $getData->invoke($component->instance());

    $claudeAi = collect($data['datasets'])->firstWhere('label', 'claude-ai');
    expect($claudeAi)->not->toBeNull()
        ->and($claudeAi['borderColor'])->toBe('#059669');
});

test('the top users leaderboard renders', function () {
    $user = User::factory()->create(['slack_handle' => 'ada']);
    Event::factory()->for($user)->create(['tokens' => 500, 'created_at' => now()->subDay()]);

    Livewire::test(TopUsersLeaderboard::class, ['filters' => ['range' => '7d']])->assertOk();
});

test('the top accounts leaderboard renders', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create();
    Event::factory()->for($user)->create(['account_id' => $account->id, 'tokens' => 500, 'created_at' => now()->subDay()]);

    Livewire::test(TopAccountsLeaderboard::class, ['filters' => ['range' => '7d']])->assertOk();
});

test('the activity heatmap widget renders', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['tokens' => 500, 'created_at' => now()->subDay()]);

    Livewire::test(ActivityHeatmap::class)->assertOk();
});

test('the fleet quota overview widget renders and flags a near-cap account', function () {
    $account = Account::factory()->create(['email' => 'hot@example.com']);
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_7d' => 92, 'reset_7d_at' => now()->addDay(), 'created_at' => now(),
    ]);

    Livewire::test(FleetQuotaOverview::class)
        ->assertOk()
        ->assertSee('hot@example.com');
});

test('the fleet quota card shows the provider badge after the email, not before', function () {
    $account = Account::factory()->create(['email' => 'ordered@example.com']);

    Livewire::test(FleetQuotaOverview::class)
        ->assertOk()
        ->assertSeeInOrder(['ordered@example.com', 'Claude']);
});

test('the fleet quota overview lists each account contributor with all-time tokens', function () {
    $account = Account::factory()->create(['email' => 'team@example.com']);
    $user = User::factory()->create(['slack_handle' => 'devon']);
    $account->users()->attach($user->id, ['status' => MembershipStatus::Tracked->value]);
    Event::factory()->for($user)->create(['account_id' => $account->id, 'tokens' => 4200, 'created_at' => now()]);

    Livewire::test(FleetQuotaOverview::class)
        ->assertOk()
        ->assertSee('devon')
        ->assertSee('4,200');
});

test('the fleet quota overview hides an untracked contributor by default', function () {
    $account = Account::factory()->create(['email' => 'team@example.com']);
    $user = User::factory()->create(['slack_handle' => 'devon']);
    $account->users()->attach($user->id, ['status' => MembershipStatus::Untracked->value]);
    Event::factory()->for($user)->create(['account_id' => $account->id, 'tokens' => 4200, 'created_at' => now()]);

    Livewire::test(FleetQuotaOverview::class)
        ->assertOk()
        ->assertDontSee('devon');
});

test('the fleet quota overview reveals an untracked contributor when show_untracked is on', function () {
    $account = Account::factory()->create(['email' => 'team@example.com']);
    $user = User::factory()->create(['slack_handle' => 'devon']);
    $account->users()->attach($user->id, ['status' => MembershipStatus::Untracked->value]);
    Event::factory()->for($user)->create(['account_id' => $account->id, 'tokens' => 4200, 'created_at' => now()]);

    Livewire::test(FleetQuotaOverview::class, ['pageFilters' => ['show_untracked' => true]])
        ->assertOk()
        ->assertSee('devon')
        ->assertSee('4,200');
});

test('the fleet quota card marks a pending member with a distinct dot from an untracked one, each with a hover tooltip', function () {
    $account = Account::factory()->create(['email' => 'team@example.com']);
    $pending = User::factory()->create(['slack_handle' => 'awaiting']);
    $untracked = User::factory()->create(['slack_handle' => 'departed']);
    $account->users()->attach($pending->id, ['status' => MembershipStatus::Pending->value]);
    $account->users()->attach($untracked->id, ['status' => MembershipStatus::Untracked->value]);
    Event::factory()->for($untracked)->create(['account_id' => $account->id, 'tokens' => 100, 'created_at' => now()]);

    Livewire::test(FleetQuotaOverview::class, ['pageFilters' => ['show_untracked' => true]])
        ->assertOk()
        ->assertSee("content: 'Pending'", false)
        ->assertSee("content: 'Untracked'", false)
        ->assertSee('aria-label="Untracked"', false)
        ->assertDontSee('title="Pending"', false);
});

test('the fleet quota overview shows a fleet-wide total usage across accounts', function () {
    $accountA = Account::factory()->create();
    $accountB = Account::factory()->create();
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['account_id' => $accountA->id, 'tokens' => 100, 'created_at' => now()]);
    Event::factory()->for($user)->create(['account_id' => $accountB->id, 'tokens' => 250, 'created_at' => now()]);

    Livewire::test(FleetQuotaOverview::class, ['pageFilters' => ['range' => 'all']])
        ->assertOk()
        ->assertSee('Total usage')
        ->assertSee('350'); // 100 + 250, the fleet-wide grand total
});

test('the fleet quota overview honors the total-across-accounts toggle', function () {
    $accountA = Account::factory()->create(['email' => 'a-team@example.com']);
    $accountB = Account::factory()->create();
    $user = User::factory()->create(['slack_handle' => 'nova']);
    $accountA->users()->attach($user->id, ['status' => MembershipStatus::Tracked->value]);
    $accountB->users()->attach($user->id, ['status' => MembershipStatus::Tracked->value]);
    Event::factory()->for($user)->create(['account_id' => $accountA->id, 'tokens' => 100, 'created_at' => now()]);
    Event::factory()->for($user)->create(['account_id' => $accountB->id, 'tokens' => 200, 'created_at' => now()]);

    Livewire::test(FleetQuotaOverview::class, ['pageFilters' => ['range' => 'all', 'total_across_accounts' => true]])
        ->assertOk()
        ->assertSee('300');
});

test('the account quota history chart renders without a record set', function () {
    Livewire::test(AccountQuotaHistoryChart::class)->assertOk();
});

/**
 * A widget's Chart.js data, read through its protected getter.
 *
 * @param  object  $widget  the mounted widget instance
 * @return array<string, mixed>
 */
function chartData(object $widget): array
{
    $getData = new ReflectionMethod($widget, 'getData');
    $getData->setAccessible(true);

    return $getData->invoke($widget);
}

test('the chart widgets plot dollars under a cost label in cost mode', function (string $widget) {
    $user = User::factory()->create(['slack_handle' => 'ada']);
    $account = Account::factory()->create();
    Event::factory()->for($user)->create(['provider' => 'claude-code', 'account_id' => $account->id, 'tokens' => 500, 'cost_micro_usd' => 1_234_560, 'created_at' => now()->subDay()]);

    $data = chartData(Livewire::test($widget, ['pageFilters' => ['range' => '7d', 'token_mode' => 'cost']])->instance());
    $dataset = $data['datasets'][0];

    expect($dataset['data'])->toContain(1.23);
})->with([
    'top users' => [TopUsersLeaderboard::class],
    'top accounts' => [TopAccountsLeaderboard::class],
    'token volume' => [TokenVolumeChart::class],
]);

test('the leaderboards name their dataset by the amount in cost mode', function (string $widget) {
    $data = chartData(Livewire::test($widget, ['pageFilters' => ['range' => '7d', 'token_mode' => 'cost']])->instance());

    expect($data['datasets'][0]['label'])->toBe('Cost (USD)');
})->with([
    'top users' => [TopUsersLeaderboard::class],
    'top accounts' => [TopAccountsLeaderboard::class],
]);

test('the activity heatmap shows dollars in cost mode', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['tokens' => 500, 'cost_micro_usd' => 1_234_560, 'created_at' => now()->subHour()]);

    Livewire::test(ActivityHeatmap::class, ['pageFilters' => ['range' => '7d', 'token_mode' => 'cost']])
        ->assertSee('$1.23')
        ->assertDontSee('1,234,560 tokens');
});

test('the fleet quota card shows account and member amounts in dollars in cost mode', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['slack_handle' => 'nova']);
    $account->users()->attach($user->id, ['status' => MembershipStatus::Tracked->value]);
    Event::factory()->for($user)->create(['account_id' => $account->id, 'tokens' => 100, 'cost_micro_usd' => 2_500_000, 'created_at' => now()]);

    Livewire::test(FleetQuotaOverview::class, ['pageFilters' => ['range' => 'all', 'token_mode' => 'cost']])
        ->assertOk()
        ->assertSee('$2.50')
        ->assertDontSee('2,500,000');
});

test('the fleet quota card marks a member with incomplete usage with a dot whose hover tooltip names the reason', function () {
    // A native title tooltip on a 5px glyph needs a still, pixel-exact hover
    // for about a second, so the reason was effectively never seen.
    $account = Account::factory()->create();
    $member = User::factory()->create(['slack_handle' => 'proxyuser', 'incomplete_events' => 3, 'incomplete_reason' => 'modified_hook']);
    $account->users()->attach($member->id, ['status' => MembershipStatus::Tracked->value]);

    Livewire::test(FleetQuotaOverview::class)
        ->assertOk()
        ->assertSee("x-tooltip=\"{ content: 'Modified hook or unknown client'", false)
        ->assertSee('aria-label="Modified hook or unknown client"', false)
        ->assertDontSee('title="Modified hook or unknown client"', false);
});
