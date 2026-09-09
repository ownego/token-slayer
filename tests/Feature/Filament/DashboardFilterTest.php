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
