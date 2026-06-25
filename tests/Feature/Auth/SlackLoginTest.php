<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User as SlackUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.slack.bot_token' => 'xoxb-test-token']);
});

/**
 * Build a mocked Slack Socialite user returned from the OAuth identity flow.
 */
function fakeSlackUser(array $overrides = []): SlackUser
{
    $slackUser = Mockery::mock(SlackUser::class);
    $slackUser->shouldReceive('getId')->andReturn($overrides['id'] ?? 'U123');
    $slackUser->shouldReceive('getName')->andReturn($overrides['name'] ?? 'Alice Liu');
    $slackUser->shouldReceive('getNickname')->andReturn($overrides['nickname'] ?? null);
    $slackUser->shouldReceive('getEmail')->andReturn($overrides['email'] ?? 'alice@example.com');
    $slackUser->shouldReceive('getAvatar')->andReturn($overrides['avatar'] ?? 'https://avatar/alice.png');

    return $slackUser;
}

function bindSlackProvider(SlackUser $slackUser): void
{
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->andReturn($slackUser);
    Socialite::shouldReceive('driver')->with('slack')->andReturn($provider);
}

function usersInfoResponse(array $profile, string $name = 'someone'): array
{
    return ['ok' => true, 'user' => ['name' => $name, 'profile' => $profile]];
}

test('slack callback stores the Slack display name in display_name', function () {
    Http::fake([
        'slack.com/api/users.info*' => Http::response(usersInfoResponse([
            'display_name' => 'sonnh',
            'real_name' => 'Nguyễn Hoàng Sơn',
        ])),
    ]);

    bindSlackProvider(fakeSlackUser(['name' => 'Nguyễn Hoàng Sơn']));

    $this->get('/auth/slack/callback')->assertRedirect('/profile');

    $user = User::sole();
    expect($user->slack_user_id)->toBe('U123')
        ->and($user->display_name)->toBe('sonnh')
        ->and($user->name)->toBe('Nguyễn Hoàng Sơn')
        ->and($user->avatar_url)->toBe('https://avatar/alice.png')
        ->and($user->hook_token)->not->toBeNull()
        ->and(auth()->id())->toBe($user->id);

    expect(session('hook_token_plain'))->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'users.info')
        && str_contains($request->url(), 'user=U123')
        && $request->hasHeader('Authorization', 'Bearer xoxb-test-token'));
});

test('slack callback falls back to the real name when display name is empty', function () {
    Http::fake([
        'slack.com/api/users.info*' => Http::response(usersInfoResponse([
            'display_name' => '',
            'real_name' => 'Nguyễn Hoàng Sơn',
        ])),
    ]);

    bindSlackProvider(fakeSlackUser(['name' => 'Nguyễn Hoàng Sơn']));

    $this->get('/auth/slack/callback')->assertRedirect('/profile');

    expect(User::sole()->display_name)->toBe('Nguyễn Hoàng Sơn');
});

test('slack callback for returning user keeps hook_token and refreshes display name', function () {
    Http::fake([
        'slack.com/api/users.info*' => Http::response(usersInfoResponse(['display_name' => 'new-display'])),
    ]);

    $existing = User::factory()->create([
        'slack_user_id' => 'U999',
        'display_name' => 'Old Display',
        'avatar_url' => 'https://avatar/old.png',
    ]);
    $originalToken = $existing->hook_token;

    bindSlackProvider(fakeSlackUser(['id' => 'U999', 'name' => 'New Name', 'avatar' => 'https://avatar/new.png']));

    $this->get('/auth/slack/callback')->assertRedirect('/battlefield');

    $existing->refresh();
    expect(User::count())->toBe(1)
        ->and($existing->hook_token)->toBe($originalToken)
        ->and($existing->display_name)->toBe('new-display')
        ->and($existing->avatar_url)->toBe('https://avatar/new.png')
        ->and(auth()->id())->toBe($existing->id);

    expect(session('hook_token_plain'))->toBeNull();
});

test('slack callback falls back to the real name when users.info fails', function () {
    Http::fake([
        'slack.com/api/users.info*' => Http::response(['ok' => false, 'error' => 'missing_scope']),
    ]);

    bindSlackProvider(fakeSlackUser(['name' => 'Real Name']));

    $this->get('/auth/slack/callback')->assertRedirect('/profile');

    expect(User::sole()->display_name)->toBe('Real Name');
});
