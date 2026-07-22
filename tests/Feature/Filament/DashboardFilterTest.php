<?php

use App\Filament\Pages\Dashboard;
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
