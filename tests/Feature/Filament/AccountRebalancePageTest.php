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

/**
 * The lopsided two-account fleet every test below reads: a whale alone on a
 * tight account and three lighter members on a roomy one, which is the shape
 * a two-way swap is the answer to.
 *
 * @return array{tight: Account, roomy: Account, whale: User}
 */
function lopsidedFleet(): array
{
    $tight = Account::factory()->connected()->create();
    $roomy = Account::factory()->connected()->create();

    foreach ([[$tight, 70], [$roomy, 35]] as [$account, $peakUtil]) {
        AccountUsageSnapshot::factory()->for($account)->create([
            'util_7d' => $peakUtil,
            'reset_7d_at' => Carbon::parse('2026-09-12 00:00:00'),
            'created_at' => Carbon::parse('2026-09-11 23:00:00'),
        ]);
    }

    $whale = User::factory()->create();
    $members = [[$tight, $whale, 150_000]];
    foreach ([70_000, 70_000, 10_000] as $tokensPerDay) {
        $members[] = [$roomy, User::factory()->create(), $tokensPerDay];
    }

    foreach ($members as [$account, $user, $tokensPerDay]) {
        foreach (range(1, 7) as $offset) {
            Event::factory()->for($account)->for($user)->create([
                'tokens' => $tokensPerDay,
                'created_at' => Carbon::parse('2026-09-04 12:00:00')->addDays($offset),
            ]);
        }

        Event::factory()->for($account)->for($user)->create([
            'tokens' => 1,
            'created_at' => Carbon::parse('2026-08-23 12:00:00'),
        ]);

        $account->users()->syncWithoutDetaching([$user->id => ['status' => MembershipStatus::Tracked->value]]);
    }

    return ['tight' => $tight, 'roomy' => $roomy, 'whale' => $whale];
}

it('is forbidden for a user without the view_usage_analytics permission', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(AccountRebalance::class)->assertForbidden();
});

it('renders the recommend action results as move rows', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $admin = User::factory()->admin()->create();
    ['tight' => $tight, 'whale' => $whale] = lopsidedFleet();

    Livewire::actingAs($admin)
        ->test(AccountRebalance::class)
        ->set('range', 'month')
        ->mountAction('recommend')
        ->callMountedAction()
        ->assertSet('computed', true)
        ->assertSet('moves.0.userId', $whale->id)
        ->assertSet('moves.0.fromAccountId', $tight->id)
        // The counterpart coming back the other way is named on the row, so
        // an admin never sees half a swap presented as a standalone move.
        ->assertSet('moves.0.swapWithUserId', fn ($id): bool => $id !== null);

    Carbon::setTestNow();
});

it('recomputes when the admin changes the range instead of leaving a stale table on screen', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $admin = User::factory()->admin()->create();
    lopsidedFleet();

    $component = Livewire::actingAs($admin)
        ->test(AccountRebalance::class)
        ->mountAction('recommend')
        ->callMountedAction()
        ->assertSet('summary.window_label', 'last 30 days');

    // A week-long range only sees the tail of the fixture's usage, so the
    // label must follow the selector without a second Recalculate.
    $component->set('range', 'week')->assertSet('summary.window_label', 'last 7 days');

    Carbon::setTestNow();
});

it('explains a move with the figures behind it', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $admin = User::factory()->admin()->create();
    lopsidedFleet();

    $component = Livewire::actingAs($admin)
        ->test(AccountRebalance::class)
        ->mountAction('recommend')
        ->callMountedAction()
        ->mountAction('explainMove', ['index' => 0])
        ->assertActionMounted('explainMove');

    // Filament renders a modal's body lazily, so the figures are asserted
    // against the partial itself, fed the row the page actually stored.
    $detail = view('filament.pages.partials.rebalance-move-detail', [
        'move' => $component->get('moves')[0],
        'summary' => $component->get('summary'),
    ])->render();

    expect($detail)
        ->toContain('Heaviest week')
        ->toContain('Quota weight')
        ->toContain('Burstiness')
        ->toContain('of its weekly capacity');

    Carbon::setTestNow();
});
