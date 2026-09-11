<?php

use App\Filament\Pages\UsageAnalytics;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('offers the all-time range on the usage analytics filter', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(UsageAnalytics::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('All time');
});

it('offers the claude-ai provider option by its real stored value, not a display-only alias', function () {
    // events.provider stores "claude-ai" (hyphen) -- verified against real
    // production data and the tracker userscript's own ?provider= query
    // param. The option's VALUE used to be "claude.ai" (dot, the human-facing
    // label), so selecting it filtered on a value no event ever has --
    // silently returning zero rows every time.
    $admin = User::factory()->admin()->create();

    $html = $this->actingAs($admin)->get(UsageAnalytics::getUrl(panel: 'admin'))->getContent();

    expect($html)->toContain('value="claude-ai"')
        ->and($html)->not->toContain('value="claude.ai"');
});
