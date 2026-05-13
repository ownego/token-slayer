<?php

use App\Models\Boss;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('battlefield page loads with no JS errors', function () {
    // Skip if no Chromium / Chrome is available — Pest 4 browser tests need it.
    $hasChrome = (bool) shell_exec('command -v chromium chromium-browser google-chrome chrome 2>/dev/null');

    if (! $hasChrome) {
        $this->markTestSkipped('No Chromium/Chrome installed — browser environment unavailable.');
    }

    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);

    $page = visit('/battlefield');

    $page->assertSee('Boss #1')
        ->assertNoJavaScriptErrors();
});

test('dispatching battlefield:hit updates the HP bar', function () {
    $hasChrome = (bool) shell_exec('command -v chromium chromium-browser google-chrome chrome 2>/dev/null');
    if (! $hasChrome) {
        $this->markTestSkipped('No Chromium/Chrome installed.');
    }

    $boss = Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);
    $fighter = User::factory()->create(['last_event_at' => now()->subMinute()]);

    $page = visit('/battlefield');

    $page->script(<<<JS
        window.dispatchEvent(new CustomEvent('battlefield:hit', {
            detail: {
                user_id: {$fighter->id},
                damage: 250,
                boss_hp_after: 750,
                boss_max_hp: 1000,
            },
        }));
    JS);

    $page->wait(800)
        ->assertSee('750 / 1,000')
        ->assertNoJavaScriptErrors();
});

test('dispatching battlefield:hit spawns a projectile node', function () {
    $hasChrome = (bool) shell_exec('command -v chromium chromium-browser google-chrome chrome 2>/dev/null');
    if (! $hasChrome) {
        $this->markTestSkipped('No Chromium/Chrome installed.');
    }

    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);
    $fighter = User::factory()->create(['last_event_at' => now()->subMinute()]);

    $page = visit('/battlefield');

    $page->script(<<<JS
        window.dispatchEvent(new CustomEvent('battlefield:hit', {
            detail: {
                user_id: {$fighter->id},
                damage: 100,
                boss_hp_after: 900,
                boss_max_hp: 1000,
            },
        }));
    JS);

    $page->assertVisible('[data-projectile]')
        ->assertNoJavaScriptErrors();
});

test('HP bar holds until projectile impact', function () {
    $hasChrome = (bool) shell_exec('command -v chromium chromium-browser google-chrome chrome 2>/dev/null');
    if (! $hasChrome) {
        $this->markTestSkipped('No Chromium/Chrome installed.');
    }

    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);
    $fighter = User::factory()->create(['last_event_at' => now()->subMinute()]);

    $page = visit('/battlefield');

    $page->script(<<<JS
        window.dispatchEvent(new CustomEvent('battlefield:hit', {
            detail: {
                user_id: {$fighter->id},
                damage: 250,
                boss_hp_after: 750,
                boss_max_hp: 1000,
            },
        }));
    JS);

    $page->wait(50)
        ->assertSee('1,000 / 1,000');

    $page->wait(500)
        ->assertSee('750 / 1,000')
        ->assertNoJavaScriptErrors();
});
