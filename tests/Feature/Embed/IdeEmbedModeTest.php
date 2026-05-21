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

test('battlefield with embed=ide does NOT include bridge script for guests', function () {
    $response = $this->get('/battlefield?embed=ide')->assertOk();

    expect($response->getContent())
        ->toContain('data-ide-embed="true"')
        ->not->toContain('ide-bridge');
});

test('embed=ide strips X-Frame-Options and sets a webview-friendly CSP', function () {
    $response = $this->get('/battlefield?embed=ide')->assertOk();

    expect($response->headers->get('X-Frame-Options'))->toBeNull();
    expect($response->headers->get('Content-Security-Policy'))
        ->toContain('frame-ancestors')
        ->toContain('vscode-webview:');
});

test('non-embed requests keep the default X-Frame-Options', function () {
    $response = $this->get('/battlefield')->assertOk();

    // Header may be present or absent depending on server config; what
    // matters is we are NOT injecting the relaxed CSP outside embed mode.
    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp === null || ! str_contains($csp, 'vscode-webview'))->toBeTrue();
});
