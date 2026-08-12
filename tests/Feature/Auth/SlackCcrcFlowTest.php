<?php

use App\Models\IdeAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

function fakeCcrcSlackUser(): SocialiteUser
{
    $u = new SocialiteUser;
    $u->map(['id' => 'U01ABCDEF', 'name' => 'Huy', 'nickname' => 'huy', 'email' => 'h@x.io', 'avatar' => null]);

    return $u;
}

it('stores state in the session when return=ccrc', function () {
    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://slack.test/oauth'));

    $this->get('/auth/slack?return=ccrc&state=STATE123')->assertRedirect();

    expect(session('ccrc_oauth'))->toBe(['state' => 'STATE123']);
});

it('redirects to callback_url with token and state', function () {
    config(['services.ccrc.callback_url' => 'https://ccrc.example.com/auth/callback']);
    User::factory()->create(['slack_user_id' => 'U01ABCDEF']);
    Socialite::shouldReceive('driver->user')->andReturn(fakeCcrcSlackUser());
    session(['ccrc_oauth' => ['state' => 'STATE123']]);

    $response = $this->get('/auth/slack/callback');

    expect($response->headers->get('Location'))
        ->toStartWith('https://ccrc.example.com/auth/callback?')
        ->toContain('state=STATE123')
        ->toContain('token=');
});

it('issues exactly one one_time_ccrc token for the ccrc flow, never the IDE kind or a bearer', function () {
    config(['services.ccrc.callback_url' => 'https://ccrc.example.com/auth/callback']);
    User::factory()->create(['slack_user_id' => 'U01ABCDEF']);
    Socialite::shouldReceive('driver->user')->andReturn(fakeCcrcSlackUser());
    session(['ccrc_oauth' => ['state' => 'STATE123']]);

    $this->get('/auth/slack/callback');

    expect(IdeAccessToken::query()->where('kind', 'one_time_ccrc')->count())->toBe(1);
    expect(IdeAccessToken::query()->where('kind', 'one_time')->count())->toBe(0);
    expect(IdeAccessToken::query()->where('kind', 'bearer')->count())->toBe(0);
});

it('ignores ?redirect= on the callback — the destination is read only from config', function () {
    config(['services.ccrc.callback_url' => 'https://ccrc.example.com/auth/callback']);
    User::factory()->create(['slack_user_id' => 'U01ABCDEF']);
    Socialite::shouldReceive('driver->user')->andReturn(fakeCcrcSlackUser());
    session(['ccrc_oauth' => ['state' => 'STATE123']]);

    $response = $this->get('/auth/slack/callback?redirect=https://evil.example.com');

    expect($response->headers->get('Location'))
        ->toStartWith('https://ccrc.example.com/auth/callback?')
        ->not->toContain('evil.example.com');
});

it('does not redirect anywhere when callback_url is not configured', function () {
    config(['services.ccrc.callback_url' => null]);
    User::factory()->create(['slack_user_id' => 'U01ABCDEF']);
    Socialite::shouldReceive('driver->user')->andReturn(fakeCcrcSlackUser());
    session(['ccrc_oauth' => ['state' => 'STATE123']]);

    $response = $this->get('/auth/slack/callback');

    $response->assertRedirect(route('battlefield'));
    expect(IdeAccessToken::count())->toBe(0);
});

it('leaves the existing IDE flow unchanged', function () {
    User::factory()->create(['slack_user_id' => 'U01ABCDEF']);
    Socialite::shouldReceive('driver->user')->andReturn(fakeCcrcSlackUser());
    session(['ide_oauth' => ['state' => 'STATE123', 'client' => 'jetbrains']]);

    $response = $this->get('/auth/slack/callback');

    expect($response->headers->get('Location'))->toStartWith('jetbrains://phpstorm/token-slayer?');
});

it('does not fire a stale ccrc_oauth on a later, unrelated login', function () {
    config(['services.ccrc.callback_url' => 'https://ccrc.example.com/auth/callback']);
    User::factory()->create(['slack_user_id' => 'U01ABCDEF']);
    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://slack.test/oauth'));

    // An abandoned ccrc attempt: the tab is closed before Slack redirects
    // back, leaving ccrc_oauth sitting in the session.
    $this->get('/auth/slack?return=ccrc&state=ABANDONED');

    // Later, in the same browser, an ordinary login starts — no return
    // param at all, nothing to do with the CCRC hub.
    $this->get('/auth/slack');

    Socialite::shouldReceive('driver->user')->andReturn(fakeCcrcSlackUser());
    $response = $this->get('/auth/slack/callback');

    expect($response->headers->get('Location'))->not->toContain('ccrc.example.com');
    expect(IdeAccessToken::count())->toBe(0);
});

it('starting a fresh return=ide login clears a leftover ccrc_oauth', function () {
    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://slack.test/oauth'));
    session(['ccrc_oauth' => ['state' => 'OLD']]);

    $this->get('/auth/slack?return=ide&state=NEW');

    expect(session('ccrc_oauth'))->toBeNull();
    expect(session('ide_oauth'))->toMatchArray(['state' => 'NEW']);
});

it('starting a fresh return=ccrc login clears a leftover ide_oauth', function () {
    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://slack.test/oauth'));
    session(['ide_oauth' => ['state' => 'OLD', 'client' => 'jetbrains', 'redirect' => null]]);

    $this->get('/auth/slack?return=ccrc&state=NEW');

    expect(session('ide_oauth'))->toBeNull();
    expect(session('ccrc_oauth'))->toBe(['state' => 'NEW']);
});

it('does not fire a stale ccrc_oauth on a later login after a retry that was followed once, then abandoned', function () {
    config(['services.ccrc.callback_url' => 'https://ccrc.example.com/auth/callback']);
    User::factory()->create(['slack_user_id' => 'U01ABCDEF']);

    $userCalls = 0;
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('redirect')->times(3)->andReturn(redirect('https://slack.test/oauth'));
    $provider->shouldReceive('user')->twice()->andReturnUsing(function () use (&$userCalls) {
        if (++$userCalls === 1) {
            throw new InvalidStateException;
        }

        return fakeCcrcSlackUser();
    });
    Socialite::shouldReceive('driver')->with('slack')->andReturn($provider);

    // Start a real ccrc login.
    $this->get('/auth/slack?return=ccrc&state=ABANDONED');

    // The first callback fails; restartLogin() sends the guest back to
    // /auth/slack, tagged with this retry's one-time ?resume= token.
    $retry = $this->get('/auth/slack/callback');
    $retry->assertRedirectContains(route('slack.login'));

    // The browser follows that exact retry URL for real, spending the
    // one-shot marker and correctly restoring ccrc_oauth for this request.
    $this->get($retry->headers->get('Location'));

    // But the retry itself is abandoned at Slack — no further callback for
    // it. Later, in the same session, an ordinary login starts: a bare
    // /auth/slack with no resume token and no return param, nothing to do
    // with the CCRC hub.
    $this->get('/auth/slack');

    $response = $this->get('/auth/slack/callback');

    expect($response->headers->get('Location'))->not->toContain('ccrc.example.com');
    expect(IdeAccessToken::count())->toBe(0);
});

it('does not let an unspent RESUME_FLOW misroute a later login when the retry redirect is never followed at all', function () {
    // Distinct from the test above: here the retry's 302 itself is never
    // followed — tab closed, connectivity lost — so RESUME_FLOW is never
    // consumed by the retry it was minted for. Without the ?resume= token
    // correlating the marker to that one specific redirect, whatever
    // /auth/slack request the server sees next — even a plain, unrelated
    // login — would look exactly like the retry continuing and inherit it.
    config(['services.ccrc.callback_url' => 'https://ccrc.example.com/auth/callback']);
    User::factory()->create(['slack_user_id' => 'U01ABCDEF']);

    $userCalls = 0;
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('redirect')->twice()->andReturn(redirect('https://slack.test/oauth'));
    $provider->shouldReceive('user')->twice()->andReturnUsing(function () use (&$userCalls) {
        if (++$userCalls === 1) {
            throw new InvalidStateException;
        }

        return fakeCcrcSlackUser();
    });
    Socialite::shouldReceive('driver')->with('slack')->andReturn($provider);

    $this->get('/auth/slack?return=ccrc&state=ABANDONED');
    $this->get('/auth/slack/callback')->assertRedirectContains(route('slack.login'));

    // The 302 above is never followed. Later, a plain login happens to be
    // the very next /auth/slack request the server sees.
    $this->get('/auth/slack');

    $response = $this->get('/auth/slack/callback');

    expect($response->headers->get('Location'))->not->toContain('ccrc.example.com');
    expect(IdeAccessToken::count())->toBe(0);
});

it('an explicit return=ccrc request is not contaminated by a different, still-armed retry marker', function () {
    // The coexistence hazard: an IDE retry is armed (never followed) when a
    // fresh, explicit return=ccrc request starts. ide_oauth must not survive
    // alongside the new ccrc_oauth — callback() checks the IDE branch first,
    // so any leaked ide_oauth would silently steal a CCRC login.
    config(['services.ccrc.callback_url' => 'https://ccrc.example.com/auth/callback']);
    User::factory()->create(['slack_user_id' => 'U01ABCDEF']);

    $userCalls = 0;
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('redirect')->twice()->andReturn(redirect('https://slack.test/oauth'));
    $provider->shouldReceive('user')->twice()->andReturnUsing(function () use (&$userCalls) {
        if (++$userCalls === 1) {
            throw new InvalidStateException;
        }

        return fakeCcrcSlackUser();
    });
    Socialite::shouldReceive('driver')->with('slack')->andReturn($provider);

    // Start (and fail) an IDE login: restartLogin() arms the marker with
    // ide_oauth — never followed.
    $this->get('/auth/slack?return=ide&client=jetbrains&state=OLD');
    $this->get('/auth/slack/callback')->assertRedirectContains(route('slack.login'));

    // Instead of following that retry, a fresh, explicit return=ccrc
    // request starts — a different, later attempt.
    $this->get('/auth/slack?return=ccrc&state=NEW');

    expect(session('ide_oauth'))->toBeNull();
    expect(session('ccrc_oauth'))->toBe(['state' => 'NEW']);

    $response = $this->get('/auth/slack/callback');

    expect($response->headers->get('Location'))
        ->toStartWith('https://ccrc.example.com/auth/callback?')
        ->toContain('state=NEW')
        ->not->toContain('jetbrains://');
});

it('a return=ccrc login survives a stale-state retry and still reaches the callback URL', function () {
    config(['services.ccrc.callback_url' => 'https://ccrc.example.com/auth/callback']);
    User::factory()->create(['slack_user_id' => 'U01ABCDEF']);

    $userCalls = 0;
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('redirect')->twice()->andReturn(redirect('https://slack.test/oauth'));
    $provider->shouldReceive('user')->twice()->andReturnUsing(function () use (&$userCalls) {
        if (++$userCalls === 1) {
            throw new InvalidStateException;
        }

        return fakeCcrcSlackUser();
    });
    Socialite::shouldReceive('driver')->with('slack')->andReturn($provider);

    // Start a real ccrc login — this is the only place ccrc_oauth is stored.
    $this->get('/auth/slack?return=ccrc&state=STATE123');

    // Slack's callback replays or expires the state: restartLogin() sends
    // the guest back to /auth/slack, tagged with this retry's one-time
    // ?resume= token.
    $retry = $this->get('/auth/slack/callback');
    $retry->assertRedirectContains(route('slack.login'));

    // The browser follows that exact retry URL for real. The resume token
    // is what proves this really is the continuation of the attempt above,
    // not a fresh, unrelated login.
    $this->get($retry->headers->get('Location'));

    // The retry succeeds — the hub callback must still fire.
    $response = $this->get('/auth/slack/callback');

    expect($response->headers->get('Location'))
        ->toStartWith('https://ccrc.example.com/auth/callback?')
        ->toContain('state=STATE123')
        ->toContain('token=');
});
