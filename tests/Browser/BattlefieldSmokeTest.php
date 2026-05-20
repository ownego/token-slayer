<?php

use App\Models\Boss;
use App\Models\User;
use App\Services\FighterChargingCache;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ensureChrome(): void
{
    $hasChrome = (bool) shell_exec('command -v chromium chromium-browser google-chrome chrome 2>/dev/null');
    if (! $hasChrome) {
        test()->markTestSkipped('No Chromium/Chrome installed — browser environment unavailable.');
    }
}

test('battlefield page mounts the Phaser canvas with no JS errors', function () {
    ensureChrome();
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);

    $page = visit('/battlefield');

    $page->wait(500); // wait for Phaser boot + ready

    expect($page->script('return document.querySelector("#battlefield-mount canvas") !== null'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('emitting bus hit updates the boss HP via the scene', function () {
    ensureChrome();
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);
    $fighter = User::factory()->create(['last_event_at' => now()->subMinute()]);

    $page = visit('/battlefield');
    $page->wait(500);

    $page->script(<<<JS
        window.__battlefield.bus.emit('hit', {
            user_id: {$fighter->id},
            boss_hp_after: 750,
            boss_max_hp: 1000,
            damage: 250,
        });
    JS);

    // The arc takes ~320ms and the HP tween another ~250ms.
    $page->wait(800);

    expect($page->script('return window.__battlefield.bossHp();'))->toBe(750);
    $page->assertNoJavaScriptErrors();
});

test('boss HP is not updated before the projectile impact', function () {
    ensureChrome();
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);
    $fighter = User::factory()->create(['last_event_at' => now()->subMinute()]);

    $page = visit('/battlefield');
    $page->wait(500);

    $page->script(<<<JS
        window.__battlefield.bus.emit('hit', {
            user_id: {$fighter->id},
            boss_hp_after: 750,
            boss_max_hp: 1000,
            damage: 250,
        });
    JS);

    $page->wait(50);
    expect($page->script('return window.__battlefield.bossHp();'))->toBe(1000);

    $page->wait(700);
    expect($page->script('return window.__battlefield.bossHp();'))->toBe(750);
    $page->assertNoJavaScriptErrors();
});

test('fighter without an avatar URL still renders on the battlefield', function () {
    ensureChrome();
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);
    $fighter = User::factory()->create([
        'avatar_url' => null,
        'last_event_at' => now()->subMinute(),
    ]);

    $page = visit('/battlefield');
    $page->wait(500);

    $hasFighter = $page->script("return window.__battlefield.scene.fighters.has({$fighter->id});");
    expect($hasFighter)->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('hit from a user not in the fighter row still applies damage', function () {
    ensureChrome();
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);

    $page = visit('/battlefield');
    $page->wait(500);

    $page->script(<<<'JS'
        window.__battlefield.bus.emit('hit', {
            user_id: 99999,
            boss_hp_after: 600,
            boss_max_hp: 1000,
            damage: 400,
        });
    JS);

    $page->wait(800);

    expect($page->script('return window.__battlefield.bossHp();'))->toBe(600);
    $page->assertNoJavaScriptErrors();
});

test('fighter with cached charging activity registers a charge on initial load', function () {
    ensureChrome();
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);
    $fighter = User::factory()->create(['last_event_at' => now()->subMinute()]);

    app(FighterChargingCache::class)->put($fighter->id, 'Bash: npm install');

    $page = visit('/battlefield');
    $page->wait(700); // Phaser boot + bootstrap loop

    $hasCharge = $page->script("return window.__battlefield.scene.charges.has({$fighter->id});");
    expect($hasCharge)->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
