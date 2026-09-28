<?php

use App\Models\IdeAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('returns a signed URL for the requested path', function () {
    $user = User::factory()->create();
    [$plain] = IdeAccessToken::issueBearer($user);

    $response = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/api/ide/auth/session-url', ['path' => '/battlefield?embed=ide'])
        ->assertOk()
        ->assertJsonStructure(['url']);

    $url = $response->json('url');

    expect($url)->toStartWith(url('/battlefield'));
    expect($url)->toContain('embed=ide');
    expect($url)->toContain('_t=');
});

test('rejects unknown paths', function () {
    $user = User::factory()->create();
    [$plain] = IdeAccessToken::issueBearer($user);

    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->postJson('/api/ide/auth/session-url', ['path' => '/evil-redirect'])
        ->assertStatus(422);
});

test('hitting a signed URL establishes a session and redirects to the clean path', function () {
    $user = User::factory()->create();
    [$plain] = IdeAccessToken::issueSessionUrl($user, '/battlefield?embed=ide', 30);

    $response = $this->get('/battlefield?embed=ide&_t='.$plain);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toBe(url('/battlefield?embed=ide'));
    expect(auth()->id())->toBe($user->id);
});

test('signed URL token is single-use', function () {
    $user = User::factory()->create();
    [$plain] = IdeAccessToken::issueSessionUrl($user, '/battlefield?embed=ide', 30);

    $this->get('/battlefield?embed=ide&_t='.$plain)->assertRedirect();

    // Second hit, now in a fresh session (logged out), should not establish.
    auth()->logout();
    $this->get('/battlefield?embed=ide&_t='.$plain)
        ->assertRedirect(route('slack.login')); // no session was set, and the page needs one
    expect(auth()->id())->toBeNull();
});

test('expired signed URL does not establish a session', function () {
    $user = User::factory()->create();
    [$plain] = IdeAccessToken::issueSessionUrl($user, '/battlefield?embed=ide', 1);

    $this->travel(2)->seconds();

    $this->get('/battlefield?embed=ide&_t='.$plain);

    expect(auth()->id())->toBeNull();
});
