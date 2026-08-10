<?php

use App\Models\IdeAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;

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

test('a return=ide login survives a stale-state retry and still lands on the vscode deep link', function () {
    $existing = User::factory()->create();

    $socialiteUser = Mockery::mock();
    $socialiteUser->shouldReceive('getId')->andReturn($existing->slack_user_id);
    $socialiteUser->shouldReceive('getName')->andReturn($existing->name);
    $socialiteUser->shouldReceive('getNickname')->andReturn($existing->slack_handle);
    $socialiteUser->shouldReceive('getEmail')->andReturn($existing->email);
    $socialiteUser->shouldReceive('getAvatar')->andReturn($existing->avatar_url);

    $userCalls = 0;
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('redirect')->twice()->andReturn(redirect('https://slack.test/oauth'));
    $provider->shouldReceive('user')->twice()->andReturnUsing(function () use (&$userCalls, $socialiteUser) {
        if (++$userCalls === 1) {
            throw new InvalidStateException;
        }

        return $socialiteUser;
    });
    Socialite::shouldReceive('driver')->with('slack')->andReturn($provider);

    // Start a real IDE login — this is the only place ide_oauth is stored.
    $this->get('/auth/slack?return=ide&client=jetbrains&state=st');

    // Slack's callback replays or expires the state: restartLogin() sends
    // the guest back to /auth/slack, tagged with this retry's one-time
    // ?resume= token.
    $retry = $this->get('/auth/slack/callback');
    $retry->assertRedirectContains(route('slack.login'));

    // The browser follows that exact retry URL for real — the resume token
    // is what proves this bare-looking revisit really is the continuation
    // of the attempt above, not a fresh, unrelated login.
    $this->get($retry->headers->get('Location'));

    // The retry succeeds — the deep link must still fire.
    $response = $this->get('/auth/slack/callback');

    $location = $response->headers->get('Location');
    expect($location)
        ->toStartWith('jetbrains://phpstorm/token-slayer?')
        ->toContain('state=st');
});

test('a plain /auth/slack clears a leftover ide_oauth', function () {
    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://slack.test/oauth'));
    session(['ide_oauth' => ['state' => 'OLD', 'client' => 'jetbrains', 'redirect' => null]]);

    $this->get('/auth/slack');

    expect(session('ide_oauth'))->toBeNull();
});
