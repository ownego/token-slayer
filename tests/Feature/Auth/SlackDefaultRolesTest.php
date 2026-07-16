<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User as SlackUser;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('grants default roles to a newly registered user', function () {
    config(['services.slack.bot_token' => 'xoxb-test-token']);

    // `Role::create([..., 'is_default' => true])` silently drops the flag in
    // the test process (guardableColumns cache poisoned by an earlier
    // migration touching Role before the column existed) — forceFill after
    // create is the verified workaround. See token-slayer KB §21.
    $role = Role::create(['name' => 'viewer', 'guard_name' => 'web']);
    $role->forceFill(['is_default' => true])->save();

    Http::fake(['slack.com/api/users.info*' => Http::response(['ok' => true, 'user' => ['name' => 'x', 'profile' => []]])]);

    $slackUser = Mockery::mock(SlackUser::class);
    $slackUser->shouldReceive('getId')->andReturn('UNEW');
    $slackUser->shouldReceive('getName')->andReturn('New Person');
    $slackUser->shouldReceive('getNickname')->andReturn(null);
    $slackUser->shouldReceive('getEmail')->andReturn('new@example.com');
    $slackUser->shouldReceive('getAvatar')->andReturn('https://a/x.png');
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->andReturn($slackUser);
    Socialite::shouldReceive('driver')->with('slack')->andReturn($provider);

    $this->get('/auth/slack/callback')->assertRedirect('/profile');

    expect(User::sole()->hasRole('viewer'))->toBeTrue();
});

it('does not re-touch roles for an existing user on repeat login', function () {
    config(['services.slack.bot_token' => 'xoxb-test-token']);

    $role = Role::create(['name' => 'viewer', 'guard_name' => 'web']);
    $role->forceFill(['is_default' => true])->save();

    Http::fake(['slack.com/api/users.info*' => Http::response(['ok' => true, 'user' => ['name' => 'x', 'profile' => []]])]);

    $existing = User::factory()->create(['slack_user_id' => 'UOLD']);
    expect($existing->hasRole('viewer'))->toBeFalse();

    $slackUser = Mockery::mock(SlackUser::class);
    $slackUser->shouldReceive('getId')->andReturn('UOLD');
    $slackUser->shouldReceive('getName')->andReturn('Old Person');
    $slackUser->shouldReceive('getNickname')->andReturn(null);
    $slackUser->shouldReceive('getEmail')->andReturn('old@example.com');
    $slackUser->shouldReceive('getAvatar')->andReturn('https://a/old.png');
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->andReturn($slackUser);
    Socialite::shouldReceive('driver')->with('slack')->andReturn($provider);

    $this->get('/auth/slack/callback')->assertRedirect('/battlefield');

    expect($existing->refresh()->hasRole('viewer'))->toBeFalse();
});
