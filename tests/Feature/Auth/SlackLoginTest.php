<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
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

/**
 * Bind a Slack provider whose user() call blows up the way a replayed or
 * expired OAuth callback does.
 */
function bindSlackProviderThrowingInvalidState(): void
{
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->andThrow(new InvalidStateException);
    Socialite::shouldReceive('driver')->with('slack')->andReturn($provider);
}

test('slack callback restarts the login flow when the oauth state is stale', function () {
    bindSlackProviderThrowingInvalidState();

    // Not an exact match: restartLogin() now tags the retry redirect with a
    // one-time ?resume= token (see RESUME_FLOW's docblock in SlackController).
    $this->get('/auth/slack/callback')->assertRedirectContains(route('slack.login'));

    expect(User::count())->toBe(0);
});

test('restarting a stale-state login preserves the originally requested page', function () {
    bindSlackProviderThrowingInvalidState();

    // A guest sent here after being bounced off /dashboard already has the
    // intended URL stashed; the restart must not discard it so the *next*
    // (successful) callback lands them back on /dashboard.
    $this->withSession(['url.intended' => url('/dashboard/accounts')])
        ->get('/auth/slack/callback')
        ->assertRedirectContains(route('slack.login'))
        ->assertSessionHas('url.intended', url('/dashboard/accounts'));
});

test('a guest bounced off the dashboard still lands there after a stale-state retry', function () {
    // Full round trip in one session: /dashboard stashes url.intended, the
    // first callback blows up on a stale state and restarts, and the retry
    // must deliver the user to /dashboard — not to a generic landing page.
    Http::fake(['slack.com/api/users.info*' => Http::response(usersInfoResponse([]))]);

    // One provider for the whole session: the first callback blows up on the
    // stale state, the retry succeeds — mirroring a real replayed callback.
    $calls = 0;
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->andReturnUsing(function () use (&$calls) {
        if (++$calls === 1) {
            throw new InvalidStateException;
        }

        return fakeSlackUser();
    });
    Socialite::shouldReceive('driver')->with('slack')->andReturn($provider);

    $this->get('/dashboard')->assertRedirect(route('slack.login'));
    $this->get('/auth/slack/callback')->assertRedirectContains(route('slack.login'));
    $this->get('/auth/slack/callback')->assertRedirect(url('/dashboard'));
});

test('slack callback stops retrying after one restart so it cannot loop', function () {
    bindSlackProviderThrowingInvalidState();

    $this->withSession(['slack_login_retried' => true])
        ->get('/auth/slack/callback')
        ->assertRedirect(route('battlefield'))
        ->assertSessionHas('error');
});

test('two consecutive stale-state failures end on the battlefield error, not a further retry', function () {
    // Full round trip, unlike the test above: the first failure's retry
    // redirect is actually followed to /auth/slack before failing again, so
    // this also proves RESUME_FLOW's one-shot consumption there doesn't
    // interfere with RETRY_FLAG's separate job of stopping the ping-pong.
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('redirect')->once()->andReturn(redirect('https://slack.test/oauth'));
    $provider->shouldReceive('user')->twice()->andThrow(new InvalidStateException);
    Socialite::shouldReceive('driver')->with('slack')->andReturn($provider);

    // First failure: restartLogin() sends the guest to /auth/slack, tagged
    // with the one-time ?resume= token this specific retry carries.
    $retry = $this->get('/auth/slack/callback');
    $retry->assertRedirectContains(route('slack.login'));

    // The browser follows that exact retry URL for real.
    $this->get($retry->headers->get('Location'));

    // The retry fails again: the guard must end the flow here instead of
    // sending the guest through yet another retry.
    $this->get('/auth/slack/callback')
        ->assertRedirect(route('battlefield'))
        ->assertSessionHas('error');

    expect(User::count())->toBe(0);
});

test('slack callback sends an already authenticated visitor on instead of erroring', function () {
    $user = User::factory()->create();
    bindSlackProviderThrowingInvalidState();

    $this->actingAs($user)
        ->withSession(['url.intended' => url('/dashboard')])
        ->get('/auth/slack/callback')
        ->assertRedirect(url('/dashboard'));
});

test('slack callback restarts the flow when slack returns no user id', function () {
    $slackUser = Mockery::mock(SlackUser::class);
    $slackUser->shouldReceive('getId')->andReturn(null);
    bindSlackProvider($slackUser);

    $this->get('/auth/slack/callback')->assertRedirectContains(route('slack.login'));

    expect(User::count())->toBe(0);
});

test('slack callback clears the retry flag once a login succeeds', function () {
    Http::fake(['slack.com/api/users.info*' => Http::response(usersInfoResponse([]))]);
    bindSlackProvider(fakeSlackUser());

    $this->withSession(['slack_login_retried' => true])
        ->get('/auth/slack/callback')
        ->assertSessionMissing('slack_login_retried');
});
