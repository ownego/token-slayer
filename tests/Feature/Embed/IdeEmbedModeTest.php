<?php

use App\Models\Boss;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000, 'current_hp' => 1_000]);
});

test('battlefield without embed renders nav and footer', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/battlefield')
        ->assertOk()
        ->assertDontSee('data-ide-embed="true"', false);
});

test('battlefield with embed=ide hides chrome and includes the bridge script', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/battlefield?embed=ide')->assertOk();

    expect($response->getContent())->toContain('data-ide-embed="true"');
    expect($response->getContent())->toContain('ide-bridge');
});

test('a guest opening the embed without the IDE\'s session URL is sent to log in', function () {
    // the plugin always signs in through its one-time `?_t=` URL first
    // (SessionUrlTest); the battlefield is no longer open to anyone
    $this->get('/battlefield?embed=ide')->assertRedirect(route('slack.login'));
});

test('embed=ide strips X-Frame-Options and sets a webview-friendly frame-ancestors CSP', function () {
    $response = $this->actingAs(User::factory()->create())->get('/battlefield?embed=ide')->assertOk();

    expect($response->headers->get('X-Frame-Options'))->toBeNull();
    expect($response->headers->get('Content-Security-Policy'))
        ->toContain('frame-ancestors')
        ->toContain('vscode-webview:');
});

test('non-embed requests do not inject the embed CSP', function () {
    $response = $this->actingAs(User::factory()->create())->get('/battlefield')->assertOk();

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp === null || ! str_contains($csp, 'vscode-webview'))->toBeTrue();
});

test('battlefield with embed=ide hides the Profile pill (the nav) so the iframe cannot blank itself out', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/battlefield?embed=ide')->assertOk();

    expect($response->getContent())->not->toContain("\$dispatch('open-fighter-sheet', { tab: 'profile' })");
});

test('battlefield without embed still renders the Profile pill for normal web users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/battlefield')->assertOk();

    expect($response->getContent())->toContain("\$dispatch('open-fighter-sheet', { tab: 'profile' })");
});
