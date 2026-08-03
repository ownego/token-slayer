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
        ->assertSee('Browse and switch interactively')
        ->assertSee('Add another account')
        ->assertSee('Switch which account is active')
        ->assertSee('Force-switch when the normal switch is stuck')
        ->assertSee('See which account is currently active')
        ->assertSee('Give a slot a short alias')
        ->assertSee('Remove an account slot')
        ->assertSee('Pull an org-provisioned account')
        ->assertSee('Register your current login as a slot')
        ->assertSee('See recent account swaps')
        ->assertSee('See which Claude Code sessions are running')
        ->assertSee('Reconcile accounts after a manual change')
        ->assertSee('Update the switcher itself')
        ->assertSee('Remove the switcher entirely')
        ->assertSee('tok add NAME')
        ->assertSee('--login', escape: false)
        ->assertSee('tok switch TARGET', escape: false)
        ->assertSee('tok force-switch TARGET', escape: false)
        ->assertSee('tok remove TARGET', escape: false)
        ->assertSee('tok alias TARGET', escape: false)
        ->assertSee('tok status')
        ->assertSee('tok tui')
        ->assertSee('tok setup')
        ->assertSee('tok current')
        ->assertSee('tok detect-base')
        ->assertSee('tok history')
        ->assertSee('tok sessions')
        ->assertSee('tok sync')
        ->assertSee('tok update')
        ->assertSee('tok uninstall');
});

test('guide explains how to discover a slot name or index before switching', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/guide')
        ->assertOk()
        ->assertSee('Not sure of the name or index?')
        ->assertDontSee('lets you switch right there');
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
