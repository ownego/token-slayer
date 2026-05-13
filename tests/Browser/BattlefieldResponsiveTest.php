<?php

use App\Models\Boss;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ensureChromeForResponsive(): void
{
    $hasChrome = (bool) shell_exec('command -v chromium chromium-browser google-chrome chrome 2>/dev/null');
    if (! $hasChrome) {
        test()->markTestSkipped('No Chromium/Chrome installed — browser environment unavailable.');
    }
}

test('battlefield boots in portrait mode on a phone-sized viewport', function () {
    ensureChromeForResponsive();
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);

    $page = visit('/battlefield')->resize(375, 812);
    $page->wait(700);

    expect($page->script('return window.__battlefield.mode'))->toBe('portrait');
    expect($page->script('return document.querySelector("#battlefield-mount canvas") !== null'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('battlefield boots in landscape mode on a tablet-sized viewport', function () {
    ensureChromeForResponsive();
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);

    $page = visit('/battlefield')->resize(1024, 768);
    $page->wait(700);

    expect($page->script('return window.__battlefield.mode'))->toBe('landscape');
    expect($page->script('return document.querySelector("#battlefield-mount canvas") !== null'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('mobile portrait viewport shows the html leaderboard button', function () {
    ensureChromeForResponsive();
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);

    $page = visit('/battlefield')->resize(375, 812);
    $page->wait(700);

    $buttonVisible = $page->script(<<<'JS'
        const overlay = document.querySelector('[x-data="battlefieldLeaderboardOverlay()"]');
        if (!overlay) return false;
        return getComputedStyle(overlay).display !== 'none';
    JS);
    expect($buttonVisible)->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('desktop viewport hides the html leaderboard overlay', function () {
    ensureChromeForResponsive();
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);

    $page = visit('/battlefield')->resize(1280, 720);
    $page->wait(700);

    $overlayHidden = $page->script(<<<'JS'
        const overlay = document.querySelector('[x-data="battlefieldLeaderboardOverlay()"]');
        if (!overlay) return false;
        return getComputedStyle(overlay).display === 'none';
    JS);
    expect($overlayHidden)->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
