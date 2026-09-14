<?php

use App\Enums\MembershipStatus;
use App\Filament\Pages\AccountRebalance;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Event;
use App\Models\RebalancePlan;
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

it('says on the rebalance table itself that its percentages are the worst case', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $admin = User::factory()->admin()->create();
    lopsidedFleet();

    // Without this the page contradicts itself: a table of accounts over
    // 100% sitting directly above a sizing panel saying the fleet fits.
    Livewire::actingAs($admin)
        ->test(AccountRebalance::class)
        ->mountAction('recommend')
        ->callMountedAction()
        ->assertSee('worst case')
        ->assertSee('the heaviest week this fleet really had was');

    Carbon::setTestNow();
});

it('sizes the fleet both ways, so a capacity decision is not made on the worst case alone', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $admin = User::factory()->admin()->create();
    lopsidedFleet();

    $component = Livewire::actingAs($admin)
        ->test(AccountRebalance::class)
        ->mountAction('recommend')
        ->callMountedAction();

    expect($component->get('capacity'))
        ->toHaveKeys(['observed', 'unconstrained', 'worst_case', 'assumed_capacity_tokens'])
        ->and($component->get('summary')['simulated_accounts'])->toBe(0);

    // Asking for one more account re-plans the fleet around it, rather than
    // leaving the table describing a fleet that is about to change.
    $component->set('extraAccounts', 1);

    expect($component->get('summary')['simulated_accounts'])->toBe(1)
        ->and(collect($component->get('accounts'))->where('is_new', true))->toHaveCount(1)
        ->and(collect($component->get('moves'))->where('toAccountIsNew', true))->not->toBeEmpty();

    Carbon::setTestNow();
});

it('offers to reclaim a seat whose holder has moved on, without moving anybody', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $admin = User::factory()->admin()->create();
    ['tight' => $tight, 'roomy' => $roomy] = lopsidedFleet();

    // A migrant: weeks on the tight account, then a clean handover to the
    // roomy one, with the old seat never given up.
    $migrant = User::factory()->create();
    livesOn($tight, $migrant, '2026-08-16 09:00:00', 10, 1_000_000);
    livesOn($roomy, $migrant, '2026-09-08 09:00:00', 4, 400_000);

    $component = Livewire::actingAs($admin)->test(AccountRebalance::class);

    expect(collect($component->instance()->staleMemberships())->firstWhere('user_id', $migrant->id))
        ->not->toBeNull();

    $component->callAction('releaseSeat', arguments: ['userId' => $migrant->id, 'accountId' => $tight->id]);

    expect($tight->users()->wherePivot('user_id', $migrant->id)->first()->pivot->status)
        ->toBe(MembershipStatus::Untracked)
        ->and($component->instance()->staleMemberships())->toBe([]);

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

it('issues the switched grant to the machine the person already uses', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $admin = User::factory()->admin()->create();
    ['tight' => $tight, 'whale' => $whale] = lopsidedFleet();

    // Their laptop has already connected, so its fingerprint is bound.
    $laptop = $whale->devices()->create(['device_id' => 'fingerprint-abc', 'name' => 'laptop']);

    $mounted = Livewire::actingAs($admin)
        ->test(AccountRebalance::class)
        ->mountAction('switchUser', ['userId' => $whale->id, 'fromAccountId' => $tight->id, 'toAccountId' => $tight->id]);

    // A bound machine only ever answers to its own fingerprint: a grant put
    // on a fresh placeholder would never reach it, while the old account was
    // demoted anyway — losing them an account and giving nothing back. So
    // the modal has to default to a machine that exists.
    // Form state carries the option key as a string.
    expect((int) $mounted->get('mountedActions.0.data.device_pk'))->toBe($laptop->id);

    Carbon::setTestNow();
});

it('ticks a completed move off without re-planning the rest', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    ['whale' => $whale] = lopsidedFleet();
    $whale->devices()->create(['device_id' => 'fingerprint-abc', 'name' => 'laptop']);

    $component = Livewire::actingAs($admin)
        ->test(AccountRebalance::class)
        ->mountAction('recommend')
        ->callMountedAction()
        ->callAction('adoptPlan');

    $planned = $component->get('moves');
    expect($planned)->not->toBeEmpty();

    // The destination has to be the identity the fixture authorises, since
    // the real exchange still verifies it.
    $destination = Account::query()->find($planned[0]['toAccountId']);
    $destination->email = 'ongtung2212002@gmail.com';
    $destination->save();

    $component->callAction('switchUser', data: ['code' => 'code#state'], arguments: [
        'userId' => $planned[0]['userId'],
        'fromAccountId' => $planned[0]['fromAccountId'],
        'toAccountId' => $destination->id,
        'index' => 0,
    ]);

    // A plan is one target arrangement and its moves are the path there.
    // Re-planning after each step abandons that target mid-swap and hands
    // back a fresh list every time, which is no way to finish anything.
    // Compared on identity rather than whole rows: a Livewire round trip
    // renders floats back differently, which says nothing about whether the
    // plan held.
    $stillPlanned = collect($component->get('moves'))->map(fn (array $m): string => $m['userId'].'>'.$m['toAccountId'])->all();
    expect($component->get('applied'))->toBe([0])
        ->and($stillPlanned)->toBe(collect($planned)->map(fn (array $m): string => $m['userId'].'>'.$m['toAccountId'])->all());

    // Recalculating is the explicit way to start again, and it clears the
    // ticks.
    $component->mountAction('recommend')->callMountedAction();
    expect($component->get('applied'))->toBe([]);

    Carbon::setTestNow();
});

it('treats a recalculation as a draft until it is applied', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $admin = User::factory()->admin()->create();
    lopsidedFleet();

    $component = Livewire::actingAs($admin)
        ->test(AccountRebalance::class)
        ->mountAction('recommend')
        ->callMountedAction();

    // A search result is not a decision: nothing is written, and reopening
    // the page finds nothing to come back to.
    expect($component->get('planId'))->toBeNull()
        ->and(RebalancePlan::query()->count())->toBe(0)
        ->and(Livewire::actingAs($admin)->test(AccountRebalance::class)->get('computed'))->toBeFalse();

    Carbon::setTestNow();
});

it('brings back the applied plan, and the ticks, after a reload', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    ['whale' => $whale] = lopsidedFleet();
    $whale->devices()->create(['device_id' => 'fingerprint-abc', 'name' => 'laptop']);

    $component = Livewire::actingAs($admin)
        ->test(AccountRebalance::class)
        ->mountAction('recommend')
        ->callMountedAction()
        ->callAction('adoptPlan');

    $planned = $component->get('moves');
    $destination = Account::query()->find($planned[0]['toAccountId']);
    $destination->email = 'ongtung2212002@gmail.com';
    $destination->save();

    $component->callAction('switchUser', data: ['code' => 'code#state'], arguments: [
        'userId' => $planned[0]['userId'],
        'fromAccountId' => $planned[0]['fromAccountId'],
        'toAccountId' => $destination->id,
        'index' => 0,
    ]);

    // Executing a plan is a browser round trip to Anthropic per move, so it
    // spans reloads as a matter of course.
    $reloaded = Livewire::actingAs($admin)->test(AccountRebalance::class);

    expect($reloaded->get('planId'))->toBe($component->get('planId'))
        ->and($reloaded->get('applied'))->toBe([0])
        ->and(collect($reloaded->get('moves'))->pluck('userId')->all())
        ->toBe(collect($planned)->pluck('userId')->all());

    Carbon::setTestNow();
});

it('leaves the applied plan alone when a newer recalculation is not applied', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    $admin = User::factory()->admin()->create();
    lopsidedFleet();

    $adopted = Livewire::actingAs($admin)
        ->test(AccountRebalance::class)
        ->mountAction('recommend')
        ->callMountedAction()
        ->callAction('adoptPlan')
        ->get('planId');

    // Somebody looks at a different range and walks away without applying it.
    Livewire::actingAs($admin)
        ->test(AccountRebalance::class)
        ->set('range', 'week')
        ->mountAction('recommend')
        ->callMountedAction();

    expect(Livewire::actingAs($admin)->test(AccountRebalance::class)->get('planId'))->toBe($adopted);

    Carbon::setTestNow();
});

it('renders an adopted plan whose moves predate the confidenceReason field', function () {
    // A plan adopted before confidenceReason was added to moveToArray() has
    // that key missing from its persisted JSON entirely — not null, absent.
    // mount() loads the latest plan unconditionally, so this reproduces the
    // 500 ("Undefined array key") that hit every admin opening the page.
    Carbon::setTestNow('2026-09-12 06:00:00');
    $admin = User::factory()->admin()->create();
    ['tight' => $tight, 'roomy' => $roomy] = lopsidedFleet();

    $move = [
        'userId' => 1, 'userLabel' => '#1',
        'fromAccountId' => $tight->id, 'fromAccountLabel' => 'a',
        'toAccountId' => $roomy->id, 'toAccountLabel' => 'b',
        'toAccountIsNew' => false,
        'swapWithUserId' => null, 'swapWithLabel' => null,
        'demandWeeklyTokens' => 1, 'demandPerDayTokens' => 1, 'demandBasis' => 'observed',
        'trailingAvgPerDayTokens' => 1, 'peakWeekTokens' => 1, 'burstFactor' => 1,
        'quotaWeight' => 1, 'quotaWeightWindows' => 1, 'daysOfHistory' => 7,
        'fromFillBeforePercent' => 70, 'fromFillAfterPercent' => 50,
        'toFillBeforePercent' => 35, 'toFillAfterPercent' => 50,
        'confident' => false,
        // confidenceReason intentionally absent.
    ];

    RebalancePlan::query()->create([
        'adopted_by' => $admin->id,
        'range' => 'trailing30',
        'extra_accounts' => 0,
        'moves' => [$move],
        'accounts' => [],
        'summary' => [
            'peak_fill_before_percent' => 70,
            'peak_fill_after_percent' => 50,
            'unplaced_tokens' => 0,
            'safety_margin_percent' => 10,
            'window_label' => 'Trailing 30 days',
            'simulated_accounts' => 0,
            'capacity_tokens' => 1,
            'peak_without_extra_percent' => null,
            'arrivals' => 0,
        ],
        'capacity' => [],
        'applied_indexes' => [],
    ]);

    Livewire::actingAs($admin)->test(AccountRebalance::class)->assertOk();

    Carbon::setTestNow();
});

it('marks a switched member pending until their machine claims the grant', function () {
    Carbon::setTestNow('2026-09-12 06:00:00');
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    ['whale' => $whale] = lopsidedFleet();
    $whale->devices()->create(['device_id' => 'fingerprint-abc', 'name' => 'laptop']);

    $component = Livewire::actingAs($admin)
        ->test(AccountRebalance::class)
        ->mountAction('recommend')
        ->callMountedAction()
        ->callAction('adoptPlan');

    $planned = $component->get('moves');
    $destination = Account::query()->find($planned[0]['toAccountId']);
    $destination->email = 'ongtung2212002@gmail.com';
    $destination->save();
    $destination->users()->detach($planned[0]['userId']);

    $component->callAction('switchUser', data: ['code' => 'code#state'], arguments: [
        'userId' => $planned[0]['userId'],
        'fromAccountId' => $planned[0]['fromAccountId'],
        'toAccountId' => $destination->id,
        'index' => 0,
    ]);

    // The grant is issued but unclaimed: the machine has yet to pick it up.
    // Calling that Tracked says setup finished when it has not, and is why
    // the blue "pending" dot never appeared for anybody switched here.
    expect($destination->users()->wherePivot('user_id', $planned[0]['userId'])->first()->pivot->status)
        ->toBe(MembershipStatus::Pending);

    Carbon::setTestNow();
});
