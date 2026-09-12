<?php

use App\Enums\MembershipStatus;
use App\Filament\Pages\Dashboard;
use App\Models\Account;
use App\Models\CodexCredential;
use App\Models\User;
use Filament\Widgets\AccountWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('drops the header global search and the welcome widget from the panel', function () {
    $panel = Filament\Facades\Filament::getPanel('admin');

    expect($panel->getGlobalSearchProvider())->toBeNull()
        ->and($panel->getWidgets())->not->toContain(AccountWidget::class);
});

it('renders the dashboard with the time filter and total-across-accounts toggle', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(Dashboard::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('This week')
        ->assertSee('Total usage across accounts');
});

it('keeps the token mode select showing Output tokens against a stale pre-feature session', function () {
    // Filament persists the Dashboard filters form to the session
    // (Pages\Dashboard\Concerns\HasFilters::$persistsFiltersInSession). An
    // admin who had the Dashboard open before this filter shipped has a
    // session array with no token_mode key at all, and fill() leaves an
    // absent key at null rather than falling back to the field's own
    // default() -- the select rendered blank ("Select an option") instead of
    // Output tokens, even though the query layer already defaults correctly.
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $sessionKey = md5(Dashboard::class).'_filters';
    session()->put($sessionKey, ['range' => 'week', 'from' => null, 'to' => null, 'total_across_accounts' => false]);

    $html = $this->get(Dashboard::getUrl(panel: 'admin'))->getContent();

    expect($html)->toContain('token_mode&quot;:&quot;output&quot;');
});

it('offers a Quota tokens option alongside Output and Total, describing what it excludes', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(Dashboard::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSeeInOrder(['Output tokens', 'Total tokens', 'Quota tokens'])
        ->assertSee('what actually counts against a rate-limit window', escape: false);
});

it('nudges an admin on a hook too old to self-update, in the topbar of every panel page', function () {
    config(['token_slayer.hook_version' => '7']);
    $admin = User::factory()->admin()->create(['hook_version' => null, 'client_version' => '1.0.0']);

    $this->actingAs($admin)
        ->get(Dashboard::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('Hook out of date')
        ->assertSeeHtml('href="'.route('update').'"')
        // A solid, deliberately non-amber fill -- the panel's own primary
        // color IS amber, so an amber badge would blend into the topbar
        // instead of reading as an alert.
        ->assertSee('ts-hook-outdated-badge', escape: false);
});

it('says nothing to an admin whose hook is current, or will self-update on its own', function () {
    config(['token_slayer.hook_version' => '7']);
    $admin = User::factory()->admin()->create(['hook_version' => '6']);

    $this->actingAs($admin)
        ->get(Dashboard::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertDontSee('Hook out of date');
});

it('shows the total active users count in the filters form, on the same row as the range/toggle', function () {
    $account = Account::factory()->create();
    $tracked = User::factory()->create();
    $account->users()->attach($tracked->id, ['status' => MembershipStatus::Tracked->value]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(Dashboard::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSeeInOrder(['Total active users', '1', 'This week', 'Total usage across accounts']);
});

it('counts distinct tracked users across claude and codex accounts, without double-counting', function () {
    $claudeAccount = Account::factory()->create();
    $codexAccount = Account::create(['email' => 'codex@example.com', 'provider' => 'codex']);
    CodexCredential::create(['account_id' => $codexAccount->id]);

    $onlyClaude = User::factory()->create();
    $onlyCodex = User::factory()->create();
    $both = User::factory()->create();
    $untracked = User::factory()->create();

    $claudeAccount->users()->attach([
        $onlyClaude->id => ['status' => MembershipStatus::Tracked->value],
        $both->id => ['status' => MembershipStatus::Tracked->value],
        $untracked->id => ['status' => MembershipStatus::Untracked->value],
    ]);
    $codexAccount->users()->attach([
        $onlyCodex->id => ['status' => MembershipStatus::Tracked->value],
        $both->id => ['status' => MembershipStatus::Tracked->value],
    ]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(Dashboard::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSeeInOrder(['Total active users', '3']);
});

it('scopes the tracked-membership existence check by the qualified account_user.status column, not the wherePivot() magic method -- that magic method only exists on the BelongsToMany relation itself, and whereHas\'s closure receives a plain related-model builder, so wherePivot() there silently falls through to the query builder\'s dynamicWhere magic and emits a bare "pivot" = "status" predicate; SQLite tolerates it, Postgres throws "column pivot does not exist"', function () {
    $account = Account::factory()->create();
    $tracked = User::factory()->create();
    $account->users()->attach($tracked->id, ['status' => MembershipStatus::Tracked->value]);
    $admin = User::factory()->admin()->create();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($admin)->get(Dashboard::getUrl(panel: 'admin'))->assertOk();

    $existenceQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'account_user'));
    expect($existenceQueries)->not->toBeEmpty();
    foreach ($existenceQueries as $sql) {
        expect($sql)->not->toContain('"pivot"');
    }
});

it('explains the toggle on a separate line per case instead of one run-on block', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(Dashboard::getUrl(panel: 'admin'))
        ->assertOk()
        // Inline style, not a `block` utility class: the panel's Tailwind build
        // omits utilities Filament doesn't use, so `class="block"` is inert and
        // the two cases run together on one line.
        ->assertSee('<span style="display:block"><strong>Off:</strong>', escape: false)
        ->assertSee('<span style="display:block"><strong>On:</strong>', escape: false)
        ->assertDontSee('&lt;span', escape: false);
});
