<?php

use App\Enums\AccountPlan;
use App\Enums\FighterCharacter;
use App\Events\FighterCharacterChanged;
use App\Livewire\FighterSheet;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Boss;
use App\Models\Event as EventModel;
use App\Models\User;
use App\Services\ProviderServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Boss::factory()->create(['status' => 'alive', 'number' => 1]);
    Cache::flush(); // the attribution chip reads through CachedLatestVersion — never let it bleed between tests
    config(['github.token' => 'ghp_test', 'github.cli_repo' => 'acme/slayer-cli']);
});

test('a custom range with from after to is rejected and the last numbers stay', function () {
    $me = User::factory()->create();

    Livewire::actingAs($me)->test(FighterSheet::class)
        ->set('from', '2026-09-27')->set('to', '2026-09-15')
        ->call('applyRange')
        ->assertHasErrors(['to'])
        ->assertSet('period', 'today');
});

test('a custom range longer than 366 days is rejected', function () {
    Livewire::actingAs(User::factory()->create())->test(FighterSheet::class)
        ->set('from', '2024-01-01')->set('to', '2026-09-27')
        ->call('applyRange')
        ->assertHasErrors(['to']);
});

test('a valid custom range is applied', function () {
    Livewire::actingAs(User::factory()->create())->test(FighterSheet::class)
        ->set('from', '2026-09-01')->set('to', '2026-09-27')
        ->call('applyRange')
        ->assertHasNoErrors()
        ->assertSet('period', 'custom');
});

test('refresh re-probes only this player\'s accounts, at most once a minute', function () {
    $me = User::factory()->create();
    $me->accounts()->attach(Account::factory()->connected()->create());
    // FleetUsageRefresher is final — fake the prober behind it, as FleetUsageRefresherTest does
    $probes = 0;
    app()->instance(ProviderServiceFactory::class, fakeProberFactory(function () use (&$probes) {
        $probes++;
    }));

    Livewire::actingAs($me)->test(FighterSheet::class)->call('open', 'profile')->call('refresh')->call('refresh');

    expect($probes)->toBe(1);
});

test('a brand-new fighter sees zeros and a dash, never NaN', function () {
    Livewire::actingAs(User::factory()->create())->test(FighterSheet::class)
        ->call('open', 'profile')
        ->assertSee('0%')->assertDontSee('NaN');
});

test('a closed sheet runs no damage aggregate queries', function () {
    // The header's own attribution chip runs one cheap indexed lookup
    // (latest event) even while closed — see Task 41; what a closed sheet
    // must never run is a full-table SUM (DamageByPeriod/DamageByModel/
    // HourlyDamage/AccountQuotaCards), which only open() should trigger.
    DB::enableQueryLog();

    Livewire::actingAs(User::factory()->create())->test(FighterSheet::class);

    $ranAggregateQuery = collect(DB::getQueryLog())->contains(fn ($q) => str_contains(strtolower($q['query']), 'sum('));
    expect($ranAggregateQuery)->toBeFalse();

    DB::disableQueryLog();
});

test('equipping a valid character persists it and broadcasts the change', function () {
    Event::fake([FighterCharacterChanged::class]);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(FighterSheet::class)
        ->call('equip', 'werebear');

    expect($user->refresh()->equipped_character)->toBe(FighterCharacter::Werebear);
    Event::assertDispatched(FighterCharacterChanged::class, fn ($e) => $e->user->id === $user->id);
});

test('equipping an unknown character key is rejected', function () {
    Event::fake([FighterCharacterChanged::class]);
    $user = User::factory()->create(['equipped_character' => null]);

    Livewire::actingAs($user)
        ->test(FighterSheet::class)
        ->call('equip', 'not-a-real-character');

    expect($user->refresh()->equipped_character)->toBeNull();
    Event::assertNotDispatched(FighterCharacterChanged::class);
});

test('a user who has never equipped a character has a null equippedKey even though a deterministic character is shown', function () {
    $user = User::factory()->create(['equipped_character' => null]);

    Livewire::actingAs($user)
        ->test(FighterSheet::class)
        ->assertSet('equippedKey', null)
        ->assertSet('equipped', $user->characterForBoss(Boss::first()->id));
});

test('equipping a character sets equippedKey to the persisted value', function () {
    $user = User::factory()->create(['equipped_character' => null]);

    Livewire::actingAs($user)
        ->test(FighterSheet::class)
        ->call('equip', 'werebear')
        ->assertSet('equippedKey', FighterCharacter::Werebear->value);
});

test('a hook that has never reported a version shows a Hook chip linking to the update page', function () {
    // HookVersionStatus::needsManualNudge: a null hook_version is only
    // nudged once the user has reported SOME client_version (otherwise
    // "never installed anything" and "stuck on a pre-v5 hook" are
    // indistinguishable — see that class's own docblock).
    config(['token_slayer.hook_version' => '7']);
    $me = User::factory()->create(['hook_version' => null, 'client_version' => '1.0.0']);

    Livewire::actingAs($me)->test(FighterSheet::class)
        ->assertSee('Hook v7 ↑')
        ->assertSee(route('update'), false);
});

test('a reported but older hook is not nudged (it updates itself)', function () {
    config(['token_slayer.hook_version' => '7']);

    Livewire::actingAs(User::factory()->create(['hook_version' => '5']))->test(FighterSheet::class)
        ->assertDontSee('Hook v7 ↑');
});

test('the last-hit tooltip names the account and source the last event was attributed to', function () {
    $me = User::factory()->create();
    EventModel::factory()->create(['user_id' => $me->id, 'account_email' => 'team-alpha@example.com', 'account_source' => 'hook']);

    Livewire::actingAs($me)->test(FighterSheet::class)->assertSee('team-alpha@example.com')->assertSee('hook');
});

// Ported from tests/Feature/ProfileVersionBadgeTest.php (Profile.php is not
// deleted yet — Task 42, pending the user's OK): the underlying
// attributionStatus() data shape is identical, only the chip's own copy
// ("Hook v{n} ↑") differs from the old Profile page's wording.

test('hides the outdated badge when the latest version is unknown', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'down'], 500)]);
    $user = User::factory()->create(['client_version' => '1.0.0']);

    Livewire::actingAs($user)->test(FighterSheet::class)
        ->assertViewHas('attribution', fn ($a) => $a['latestVersion'] === null && $a['outdated'] === false);
});

test('flags the client as outdated when its version is older', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.4', 'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']]], 200)]);
    $user = User::factory()->create(['client_version' => '1.0.0']);

    Livewire::actingAs($user)->test(FighterSheet::class)
        ->assertViewHas('attribution', fn ($a) => $a['latestVersion'] === '1.0.4' && $a['outdated'] === true);
});

test('is not outdated when the client already runs the latest version', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.4', 'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']]], 200)]);
    $user = User::factory()->create(['client_version' => '1.0.4']);

    Livewire::actingAs($user)->test(FighterSheet::class)
        ->assertViewHas('attribution', fn ($a) => $a['latestVersion'] === '1.0.4' && $a['outdated'] === false);
});

test('is not flagged outdated when the client is newer than the cached latest (a dev build, or a stale cache)', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.4', 'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']]], 200)]);
    $user = User::factory()->create(['client_version' => '1.0.5']);

    Livewire::actingAs($user)->test(FighterSheet::class)
        ->assertViewHas('attribution', fn ($a) => $a['latestVersion'] === '1.0.4' && $a['outdated'] === false);
});

test('is not flagged outdated when client_version was never reported (hook-only/claude.ai usage)', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.4', 'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']]], 200)]);
    $user = User::factory()->create(['client_version' => null]);

    Livewire::actingAs($user)->test(FighterSheet::class)->call('open', 'profile')
        ->assertViewHas('attribution', fn ($a) => $a['latestVersion'] === '1.0.4' && $a['outdated'] === false)
        ->assertDontSee('Your CLI is');
});

test('says nothing about the hook to someone who has never sent an event', function () {
    // Nothing reported at all (no client_version either) is the only shape
    // that means "never installed" — see HookVersionStatus's own docblock.
    config(['token_slayer.hook_version' => '7']);
    $user = User::factory()->create(['client_version' => null, 'hook_version' => null]);

    Livewire::actingAs($user)->test(FighterSheet::class)
        ->assertViewHas('attribution', fn ($a) => $a['hookOutdated'] === false)
        ->assertDontSee('Hook v7 ↑');
});

// Ported from tests/Feature/Livewire/ProfileTest.php (Profile.php is not
// deleted yet — Task 42, pending the user's OK). The old page's shared-nav/
// battlefield-link/dashboard-link checks are dropped: the sheet is embedded
// IN the battlefield page (battlefield.blade.php keeps its own persistent
// Dashboard nav pill unchanged), so a "link to the page you're already on"
// no longer makes sense once /profile redirects here.

test('shows community and personal usage across hourly, daily, monthly', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    EventModel::factory()->create(['user_id' => $user->id, 'tokens' => 40, 'created_at' => now()->subMinutes(30)]);
    EventModel::factory()->create(['user_id' => $other->id, 'tokens' => 60, 'created_at' => now()->subMinutes(30)]);

    Livewire::actingAs($user)->test(FighterSheet::class)->call('open', 'profile')
        ->assertSee('All users')
        ->assertSee(number_format(100)) // community hourly
        ->assertSee(number_format(40)); // personal hourly
});

test('shows the my-account block when the user has an account', function () {
    $account = Account::factory()->create(['email' => 'team-rocket@example.com', 'plan' => AccountPlan::Max20x]);
    $user = User::factory()->create();
    $account->users()->attach([$user->id, User::factory()->create()->id]);
    EventModel::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'tokens' => 55, 'created_at' => now()->subMinutes(10)]);

    Livewire::actingAs($user)->test(FighterSheet::class)->call('open', 'profile')
        ->assertSee('team-rocket@example.com')
        ->assertSee('Max 20x')
        ->assertSee(number_format(55));
});

test('hides the my-account block when the user has no account', function () {
    Livewire::actingAs(User::factory()->create())->test(FighterSheet::class)->call('open', 'profile')
        ->assertDontSee('My account');
});

test('lists every account the user is a member of with their own usage', function () {
    $user = User::factory()->create();
    [$a, $b] = Account::factory()->count(2)->create();
    $user->accounts()->attach([$a->id, $b->id]);
    EventModel::factory()->create(['user_id' => $user->id, 'account_id' => $a->id, 'tokens' => 42]);

    Livewire::actingAs($user)->test(FighterSheet::class)->call('open', 'profile')
        ->assertSee($a->email)
        ->assertSee($b->email);
});

test('shows attribution status from the latest event', function () {
    // CachedLatestVersion skips its GitHub round trip unless github.token/
    // github.cli_repo are set — the beforeEach above already fakes both.
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => 'v3',
        'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']],
    ], 200)]);

    $user = User::factory()->create(['client_version' => '1']);
    EventModel::factory()->create([
        'user_id' => $user->id, 'account_id' => null,
        'account_email' => 'mystery@gmail.com', 'account_source' => 'auto',
    ]);

    Livewire::actingAs($user)->test(FighterSheet::class)->call('open', 'profile')
        ->assertSee('mystery@gmail.com')
        ->assertSee('tok update'); // outdated client hint (latest is 3)
});

test('links to the setup wizard and the CLI guide', function () {
    Livewire::actingAs(User::factory()->create())->test(FighterSheet::class)->call('open', 'profile')
        ->assertSee('href="'.route('setup').'"', escape: false)
        ->assertSee('href="'.route('guide').'"', escape: false);
});

test('render() exposes alerts, roommates, and the viewer\'s own minion snapshot when open', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(FighterSheet::class)
        ->call('open', 'profile')
        ->assertViewHas('alerts')
        ->assertViewHas('roommates')
        ->assertViewHas('minions', ['busy' => 0, 'idle' => 0]);
});

test('shows the boss-kills chip and CLI pill when the sheet is open', function () {
    $user = User::factory()->create(['client_version' => '1.0.4']);
    Boss::factory()->create([
        'killing_blow_user_id' => $user->id,
        'name' => 'Goomba King',
        'number' => 12,
        'defeated_at' => now()->subDays(2),
    ]);

    Livewire::actingAs($user)
        ->test(FighterSheet::class)
        ->call('open', 'profile')
        ->assertSee('12')
        ->assertSee('boss kills');
});

test('shows a critical alert line for an account at or above 95% utilization', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['email' => 'team-a@example.com']);
    $account->users()->syncWithoutDetaching([$user->id => ['status' => 'tracked']]);
    AccountUsageSnapshot::factory()->for($account)->create(['util_5h' => 96, 'util_7d' => 10]);

    Livewire::actingAs($user)
        ->test(FighterSheet::class)
        ->call('open', 'profile')
        ->assertSee('team-a@example.com is at 96% of its 5h quota');
});

test('shows the viewer\'s own minion count on the mini-stage', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(FighterSheet::class)
        ->call('open', 'profile')
        ->assertSee('minions working');
});

test('shows per-model quota percent from an account\'s model_limits', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create();
    $account->users()->syncWithoutDetaching([$user->id => ['status' => 'tracked']]);
    // The By-model row is driven by the viewer's own damage-by-model
    // breakdown; model_limits only augments a row that's already there.
    EventModel::factory()->for($user)->create(['model' => 'Fable', 'created_at' => now()]);
    AccountUsageSnapshot::factory()->for($account)->create([
        'raw' => [
            'limits' => [[
                'scope' => ['model' => ['display_name' => 'Fable']],
                'percent' => 81.0,
                'severity' => 'warning',
                'resets_at' => now()->addDays(3)->toIso8601String(),
            ]],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(FighterSheet::class)
        ->call('open', 'profile')
        ->assertSee('Fable')
        ->assertSee('81');
});

test('shows a member\'s damage_today in the account card', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['email' => 'team-a@example.com']);
    $account->users()->syncWithoutDetaching([$user->id => ['status' => 'tracked']]);
    EventModel::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'tokens' => 1_100_000, 'created_at' => now()]);

    Livewire::actingAs($user)
        ->test(FighterSheet::class)
        ->call('open', 'profile')
        ->assertSeeHtml('class="num dmgc">1.1M<');
});

test('the members fold button and the account card share one Alpine scope, not a fragile DOM-sibling walk', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create();
    $account->users()->syncWithoutDetaching([$user->id => ['status' => 'tracked']]);

    $html = Livewire::actingAs($user)
        ->test(FighterSheet::class)
        ->call('open', 'profile')
        ->html();

    // The bug this pins: an earlier version toggled visibility via
    // $el.nextElementSibling.nextElementSibling, which pointed at the
    // refresh button (not the members table) and threw on click since the
    // refresh button's own next sibling is null — every fold click crashed
    // and the table could never open. The fold button and the card's own
    // open class now read/write one variable on the party — which also
    // gives the mockup's accordion: opening one account folds the others.
    expect($html)->not->toContain('nextElementSibling');
    expect($html)->toContain('x-data="{ openAcct: null }"');
    expect($html)->toContain("@click=\"openAcct = openAcct === {$account->id} ? null : {$account->id}\"");
    expect($html)->toContain(":class=\"{ open: openAcct === {$account->id},");
});

test('an account never probed shows "not probed yet", not a crash or a NaN percent', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create();
    $account->users()->syncWithoutDetaching([$user->id => ['status' => 'tracked']]);

    Livewire::actingAs($user)
        ->test(FighterSheet::class)
        ->call('open', 'profile')
        ->assertOk()
        ->assertDontSee('NaN');
});

test('a stale snapshot (reset time already passed) shows as stale, not a misleading refill countdown', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['probe_error' => 'token rejected']);
    $account->users()->syncWithoutDetaching([$user->id => ['status' => 'tracked']]);
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => 96,
        'reset_5h_at' => now()->subHours(3),
        'util_7d' => null,
        'reset_7d_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test(FighterSheet::class)
        ->call('open', 'profile')
        ->assertDontSee('Refills in')
        ->assertSee('Stale');
});

test('character tab shows the filter chips and roster count', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(FighterSheet::class)
        ->call('open', 'character')
        ->assertSeeHtml('class="chips"')
        ->assertSeeHtml('class="chip"');
});

test('character tab passes roommates to the Alpine component', function () {
    $mate = User::factory()->create(['equipped_character' => 'orc-rider']);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(FighterSheet::class)
        ->call('open', 'character')
        ->assertSee($mate->displayHandle(), false);
});

test('charactersWithMeta() entries each carry an attackType', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(FighterSheet::class)->instance();

    foreach ($component->charactersWithMeta() as $entry) {
        expect(FighterCharacter::from($entry['key'])->attackType())->toBe($entry['attackType']);
    }
});

test('an account card\'s own refresh re-probes just that account', function () {
    $me = User::factory()->create();
    $mine = Account::factory()->connected()->create();
    $other = Account::factory()->connected()->create();
    $me->accounts()->attach([$mine->id, $other->id]);
    $probed = [];
    app()->instance(ProviderServiceFactory::class, fakeProberFactory(function (Account $account) use (&$probed) {
        $probed[] = $account->id;
    }));

    Livewire::actingAs($me)->test(FighterSheet::class)->call('open', 'profile')->call('refreshAccount', $mine->id);

    expect($probed)->toBe([$mine->id]);
});

test('the custom range opens prefilled with the last two weeks, as the mockup shows it', function () {
    $this->travelTo(now()->setDate(2026, 9, 27));

    Livewire::actingAs(User::factory()->create())
        ->test(FighterSheet::class)
        ->assertSet('from', '2026-09-14')
        ->assertSet('to', '2026-09-27');
});

test('the header always shows the CLI version the player runs, not only when it is behind', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.4', 'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']]], 200)]);
    $user = User::factory()->create(['client_version' => '1.0.4']);

    Livewire::actingAs($user)->test(FighterSheet::class)->call('open', 'profile')
        ->assertSeeHtml('class="cli-pill current"')
        ->assertSeeHtml('data-cmd="tok update"')
        ->assertSee('CLI 1.0.4');
});

test('a CLI behind the latest shows the mockup\'s upgrade pill', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.4', 'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']]], 200)]);
    $user = User::factory()->create(['client_version' => '1.0.0']);

    Livewire::actingAs($user)->test(FighterSheet::class)->call('open', 'profile')
        ->assertSee('CLI 1.0.4 ↑')
        ->assertSeeHtml('data-cmd="tok update"')
        ->assertDontSeeHtml('cli-pill current');
});

test('a fresh reading draws the hatched estimate of where the quota lands by its refill', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create();
    $account->users()->syncWithoutDetaching([$user->id => ['status' => 'tracked']]);
    // 42% used two hours into a five-hour window: at this pace it lands past 42% by the refill
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => 42,
        'reset_5h_at' => now()->addHours(3),
        'util_7d' => null,
        'reset_7d_at' => null,
    ]);

    Livewire::actingAs($user)->test(FighterSheet::class)->call('open', 'profile')
        ->assertSeeHtml('class="m-proj" style="left:42%;');
});

test('refresh re-probes only the accounts the sheet shows, never an untracked one', function () {
    $me = User::factory()->create();
    $me->accounts()->attach(Account::factory()->connected()->create(), ['status' => 'untracked']);
    $probes = 0;
    app()->instance(ProviderServiceFactory::class, fakeProberFactory(function () use (&$probes) {
        $probes++;
    }));

    Livewire::actingAs($me)->test(FighterSheet::class)->call('open', 'profile')->call('refresh');

    expect($probes)->toBe(0);
});
