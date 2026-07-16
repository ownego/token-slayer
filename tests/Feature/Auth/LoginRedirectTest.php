<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User as SlackUser;

uses(RefreshDatabase::class);

/**
 * Bind a mocked Slack Socialite user for the callback flow.
 */
function fakeSlackCallbackUser(string $id, string $email): void
{
    $slackUser = Mockery::mock(SlackUser::class);
    $slackUser->shouldReceive('getId')->andReturn($id);
    $slackUser->shouldReceive('getName')->andReturn('Person');
    $slackUser->shouldReceive('getNickname')->andReturn(null);
    $slackUser->shouldReceive('getEmail')->andReturn($email);
    $slackUser->shouldReceive('getAvatar')->andReturn('https://a/x.png');

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->andReturn($slackUser);
    Socialite::shouldReceive('driver')->with('slack')->andReturn($provider);

    config(['services.slack.bot_token' => 'xoxb-test-token']);
    Http::fake(['slack.com/api/users.info*' => Http::response(['ok' => true, 'user' => ['name' => 'x', 'profile' => []]])]);
}

it('stashes the intended admin URL and sends a guest to Slack login', function () {
    $this->get('/admin')
        ->assertRedirect(route('slack.login'))
        ->assertSessionHas('url.intended', fn (string $v): bool => str_contains($v, '/admin'));
});

it('stashes the intended profile URL and sends a guest to Slack login', function () {
    $this->get('/profile')
        ->assertRedirect(route('slack.login'))
        ->assertSessionHas('url.intended', fn (string $v): bool => str_contains($v, '/profile'));
});

it('returns to the intended URL after Slack login', function () {
    fakeSlackCallbackUser('UINT', 'intended@example.com');

    $this->withSession(['url.intended' => url('/admin/users')])
        ->get('/auth/slack/callback')
        ->assertRedirect(url('/admin/users'));
});

it('falls back to the default landing page when no intended URL was stashed', function () {
    fakeSlackCallbackUser('UDEF', 'new@example.com');

    // No url.intended in session → new user lands on their default (profile).
    $this->get('/auth/slack/callback')->assertRedirect(route('profile'));
});
