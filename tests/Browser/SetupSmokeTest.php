<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ensureChromeForSetup(): void
{
    $hasChrome = (bool) shell_exec('command -v chromium chromium-browser google-chrome chrome 2>/dev/null');
    if (! $hasChrome) {
        test()->markTestSkipped('No Chromium/Chrome installed — browser environment unavailable.');
    }
}

test('the CLI track can be clicked through end to end with no JS errors', function () {
    ensureChromeForSetup();
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/setup');
    $page->click('Claude CLI');
    $page->click('macOS');
    $page->click('Other / 3.14 / error'); // exercises the nested python-fix branch
    $page->assertSee('brew --version');
    $page->assertNoJavaScriptErrors();
});

test('the CLI track back button preserves the platform choice', function () {
    ensureChromeForSetup();
    $this->actingAs(User::factory()->create());

    $page = visit('/setup');
    $page->click('Claude CLI');
    $page->click('macOS');
    $page->click('← Back');
    $page->assertSee('Choose your platform');
    $page->click('macOS'); // re-clicking a still-selected macOS should still advance normally
    $page->assertSee('Check Python');
    $page->assertNoJavaScriptErrors();
});

test('the Cowork track renders without Linux as a platform option', function () {
    ensureChromeForSetup();
    $this->actingAs(User::factory()->create());

    $page = visit('/setup');
    $page->click('Claude Cowork');
    $page->assertSee('macOS');
    $page->assertSee('Windows');
    $page->assertDontSee('Linux');
    $page->assertNoJavaScriptErrors();
});

test('the Claude chat track requires no platform step', function () {
    ensureChromeForSetup();
    $this->actingAs(User::factory()->create());

    $page = visit('/setup');
    $page->click('Claude chat');
    $page->assertSee('Tampermonkey');
    $page->assertNoJavaScriptErrors();
});
