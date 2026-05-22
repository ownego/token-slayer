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

test('battlefield with embed=ide includes the bridge script for guests too, but omits the user-id meta', function () {
    $response = $this->get('/battlefield?embed=ide')->assertOk();

    expect($response->getContent())
        ->toContain('data-ide-embed="true"')
        ->toContain('ide-bridge')
        ->toContain('content="guest"')
        ->not->toContain('aiorg-user-id');
});

test('embed=ide strips X-Frame-Options and sets a webview-friendly frame-ancestors CSP', function () {
    $response = $this->get('/battlefield?embed=ide')->assertOk();

    expect($response->headers->get('X-Frame-Options'))->toBeNull();
    expect($response->headers->get('Content-Security-Policy'))
        ->toContain('frame-ancestors')
        ->toContain('vscode-webview:');
});

test('non-embed requests do not inject the embed CSP', function () {
    $response = $this->get('/battlefield')->assertOk();

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp === null || ! str_contains($csp, 'vscode-webview'))->toBeTrue();
});
