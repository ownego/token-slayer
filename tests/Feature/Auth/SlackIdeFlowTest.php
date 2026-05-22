<?php

use App\Models\IdeAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

test('redirect endpoint preserves IDE state in the session', function () {
    $this->withSession([])->get('/auth/slack?return=ide&state=ide-state-1');

    expect(session('ide_oauth'))->toMatchArray([
        'state' => 'ide-state-1',
    ]);
});

test('callback mints a one-time token and redirects to vscode://', function () {
    $existing = User::factory()->create();

    $socialiteUser = Mockery::mock();
    $socialiteUser->shouldReceive('getId')->andReturn($existing->slack_user_id);
    $socialiteUser->shouldReceive('getName')->andReturn($existing->name);
    $socialiteUser->shouldReceive('getNickname')->andReturn($existing->slack_handle);
    $socialiteUser->shouldReceive('getEmail')->andReturn($existing->email);
    $socialiteUser->shouldReceive('getAvatar')->andReturn($existing->avatar_url);

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $response = $this->withSession(['ide_oauth' => ['state' => 'st']])
        ->get('/auth/slack/callback');

    $response->assertRedirect();
    $location = $response->headers->get('Location');

    expect($location)->toStartWith('vscode://token-slayer.token-slayer/auth?');
    expect($location)->toContain('state=st');
    expect($location)->toContain('token=');

    parse_str(parse_url($location, PHP_URL_QUERY), $params);
    expect(IdeAccessToken::consumeOneTime($params['token'], 'st')?->id)->toBe($existing->id);
});

test('callback without ide_oauth session falls through to normal redirect', function () {
    $existing = User::factory()->create();

    $socialiteUser = Mockery::mock();
    $socialiteUser->shouldReceive('getId')->andReturn($existing->slack_user_id);
    $socialiteUser->shouldReceive('getName')->andReturn($existing->name);
    $socialiteUser->shouldReceive('getNickname')->andReturn($existing->slack_handle);
    $socialiteUser->shouldReceive('getEmail')->andReturn($existing->email);
    $socialiteUser->shouldReceive('getAvatar')->andReturn($existing->avatar_url);

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->get('/auth/slack/callback')->assertRedirect(route('battlefield'));
});
