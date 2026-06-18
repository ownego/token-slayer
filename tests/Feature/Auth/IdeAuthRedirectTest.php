<?php

use App\Models\IdeAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

function fakeSlackUser(): SocialiteUser
{
    $u = new SocialiteUser();
    $u->map(['id' => 'U1', 'name' => 'Tess', 'nickname' => 'tess', 'email' => 't@x.io', 'avatar' => null]);

    return $u;
}

it('redirects to jetbrains deep link when client=jetbrains', function () {
    User::factory()->create(['slack_user_id' => 'U1']);
    Socialite::shouldReceive('driver->user')->andReturn(fakeSlackUser());

    session(['ide_oauth' => ['state' => 'STATE123', 'client' => 'jetbrains']]);

    $response = $this->get('/auth/slack/callback');

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toStartWith('jetbrains://php-storm/token-slayer?')
        ->toContain('state=STATE123');
});

it('still redirects to vscode scheme by default', function () {
    User::factory()->create(['slack_user_id' => 'U1']);
    Socialite::shouldReceive('driver->user')->andReturn(fakeSlackUser());

    session(['ide_oauth' => ['state' => 'STATE123']]);

    $response = $this->get('/auth/slack/callback');

    expect($response->headers->get('Location'))
        ->toStartWith('vscode://token-slayer.token-slayer/auth?');
});

it('captures client param on redirect into session', function () {
    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://slack.test'));

    $this->get('/auth/slack?return=ide&client=jetbrains&state=STATE123');

    expect(session('ide_oauth'))->toMatchArray(['state' => 'STATE123', 'client' => 'jetbrains']);
});
