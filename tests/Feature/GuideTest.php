<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guide redirects guests to the slack login route', function () {
    $this->get('/guide')->assertRedirect(route('slack.login'));
});

test('guide shows the accounts and custom hook display topics', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/guide')
        ->assertOk()
        ->assertSee('Accounts')
        ->assertSee('Custom hook display');
});

test('guide lists every account task with its tok command', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/guide')
        ->assertOk()
        ->assertSee('See all your accounts')
        ->assertSee('Add another account')
        ->assertSee('Switch which account is active')
        ->assertSee('See which account is currently active')
        ->assertSee('Check version, namespace, and credential status')
        ->assertSee('Set or clear a short alias for a slot')
        ->assertSee('Remove an account slot')
        ->assertSee('Pull an org-provisioned account')
        ->assertSee('tok add NAME')
        ->assertSee('tok switch NAME', escape: false)
        ->assertSee('tok remove NAME')
        ->assertSee('tok alias NAME ALIAS')
        ->assertSee('tok status')
        ->assertSee('tok setup')
        ->assertSee('tok current');
});

test('guide explains how to discover a slot name or index before switching', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/guide')
        ->assertOk()
        ->assertSee('Not sure of the name or index?');
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
