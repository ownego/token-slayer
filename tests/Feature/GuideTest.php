<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guide redirects guests to the slack login route', function () {
    $this->get('/guide')->assertRedirect(route('slack.login'));
});

test('guide lists every token-slayer subcommand with a description', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/guide')
        ->assertOk()
        ->assertSee('token-slayer list')
        ->assertSee('token-slayer switch NAME')
        ->assertSee('token-slayer add NAME')
        ->assertSee('token-slayer status')
        ->assertSee('token-slayer setup')
        ->assertSee('token-slayer remove NAME')
        ->assertSee('token-slayer alias NAME ALIAS')
        ->assertSee('token-slayer current');
});

test('guide shows the custom.sh tool catalog reference', function () {
    config(['app.hook_namespace' => 'token_slayer']);
    $this->actingAs(User::factory()->create());

    $this->get('/guide')
        ->assertOk()
        ->assertSee('custom_activity')
        ->assertSee('~/.config/token_slayer/custom.sh')
        ->assertSee('mcp__')
        ->assertSee('CommandLine')
        ->assertSee('no per-tool events today');
});

test('guide reflects the configured hook namespace in the custom.sh path', function () {
    config(['app.hook_namespace' => 'acme']);
    $this->actingAs(User::factory()->create());

    $this->get('/guide')
        ->assertOk()
        ->assertSee('~/.config/acme/custom.sh')
        ->assertDontSee('token_slayer');
});

test('guide shows the shared account nav with guide active', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/guide')
        ->assertOk()
        ->assertSee('href="'.route('profile').'"', escape: false)
        ->assertSee('href="'.route('setup').'"', escape: false)
        ->assertSee('href="'.route('guide').'"', escape: false);
});
