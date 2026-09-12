<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\IdeAccessToken;
use App\Models\User;
use App\Services\Slack\SlackProfileFetcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class SlackController extends Controller
{
    /**
     * Cache key prefix (per client IP) marking that this visitor already had
     * one OAuth round trip restarted, so a permanently broken session can't
     * ping-pong forever. Deliberately NOT stored in the session: the trigger
     * for this guard is exactly "the session lost track of state across the
     * round trip to Slack and back," so a session-backed flag fails open —
     * every retry looks like a first attempt again and the loop never
     * breaks (observed live: tens of thousands of `/auth/slack` hits from
     * the same visitors). Keyed by IP with a short TTL instead, since that
     * survives even when the session cookie doesn't.
     *
     * @var string
     */
    private const string RETRY_CACHE_PREFIX = 'slack_login_retried:';

    /**
     * How long a restarted attempt is remembered for, in seconds — long
     * enough to cover one real round trip through Slack's authorize screen,
     * short enough that a genuinely new visit from the same IP shortly after
     * isn't mistaken for a retry of an old, abandoned one.
     *
     * @var int
     */
    private const int RETRY_TTL_SECONDS = 30;

    /**
     * Session key holding the flow state (`ide_oauth`/`ccrc_oauth`) that
     * restartLogin()'s retry needs to resume, plus a one-time `token` bound
     * into the retry's own redirect URL as `?resume=`.
     *
     * A marker alone can't tell "the browser actually following this retry"
     * apart from "some later, unrelated `/auth/slack` visit that happens to
     * arrive first" — both are bare requests, indistinguishable without extra
     * correlation. The `token` supplies that: redirect() restores the stored
     * state only when the request's own `?resume=` matches it exactly, and
     * always discards the marker on sight regardless. So a retry whose 302 is
     * never followed can't be inherited by whatever `/auth/slack` request
     * comes next, and an explicit new return=ide/ccrc request can't coexist
     * with flow state left behind by a different, superseded attempt.
     *
     * @var string
     */
    private const string RESUME_FLOW = 'slack_login_resume';

    public function __construct(private SlackProfileFetcher $profiles) {}

    public function redirect(Request $request): SymfonyRedirectResponse
    {
        // A login attempt is described by the request that started it, never
        // by an earlier attempt abandoned mid-flow.
        session()->forget(['ide_oauth', 'ccrc_oauth']);

        $return = $request->query('return');
        $resumeToken = $request->query('resume');

        // RESUME_FLOW is always consumed here, one request after it's set —
        // but its contents are only restored when this request's own
        // ?resume= matches the token restartLogin() minted for it. Anything
        // else (no token, a mismatch, or an explicit return=ide/ccrc request)
        // means this isn't that exact retry, so the marker is discarded.
        $resume = session()->pull(self::RESUME_FLOW);

        if (
            $return === null
            && is_array($resume)
            && is_string($resumeToken)
            && hash_equals($resume['token'] ?? '', $resumeToken)
        ) {
            foreach (['ide_oauth', 'ccrc_oauth'] as $key) {
                if (isset($resume[$key])) {
                    session()->put($key, $resume[$key]);
                }
            }
        }

        if ($return === 'ide' && is_string($state = $request->query('state'))) {
            $client = $request->query('client');
            $redirect = $request->query('redirect');
            session()->put('ide_oauth', [
                'state' => $state,
                'client' => $client === 'jetbrains' ? 'jetbrains' : 'vscode',
                'redirect' => is_string($redirect) && $this->isLoopbackUrl($redirect) ? $redirect : null,
            ]);
        }

        if ($return === 'ccrc' && is_string($state = $request->query('state'))) {
            // Only `state` is kept. No `client`, no `redirect`: this branch's
            // destination comes from config, so there is nothing dynamic to
            // accept from the query string.
            session()->put('ccrc_oauth', ['state' => $state]);
        }

        return Socialite::driver('slack')->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $slack = Socialite::driver('slack')->user();
        } catch (InvalidStateException) {
            return $this->restartLogin();
        }

        $slackUserId = $slack->getId();

        if (! is_string($slackUserId) || $slackUserId === '') {
            return $this->restartLogin();
        }

        $existing = User::where('slack_user_id', $slackUserId)->first();

        $attributes = [
            'name' => $slack->getName() ?? $slack->getNickname(),
            'email' => $slack->getEmail() ?? $slackUserId.'@slack.local',
            'slack_handle' => $slack->getNickname(),
            'display_name' => $this->profiles->displayNameFor($slackUserId) ?? $slack->getName(),
            'avatar_url' => $slack->getAvatar(),
        ];

        if ($existing === null) {
            $plainToken = Str::random(48);

            $user = User::create([
                ...$attributes,
                'slack_user_id' => $slackUserId,
                'hook_token' => hash('sha256', $plainToken),
            ]);

            session()->put('hook_token_plain', $plainToken);
            $defaultRoute = 'profile';
        } else {
            $existing->update($attributes);
            $user = $existing;
            $defaultRoute = 'battlefield';
        }

        // Remembered so the session's own 120-minute idle timeout (a login
        // via Slack has no password to re-prompt for) doesn't force a full
        // OAuth round trip every couple hours of inactivity — Laravel's
        // remember cookie transparently re-authenticates on the next visit.
        auth()->login($user, remember: true);
        Cache::forget($this->retryCacheKey());

        if (($ide = $this->consumeIdeFlowState()) !== null) {
            return $this->redirectToIde($user, $ide['state'], $ide['client'], $ide['redirect']);
        }

        if (($ccrc = $this->consumeCcrcFlowState()) !== null) {
            return $this->redirectToCcrc($user, $ccrc['state']);
        }

        // Send the user back to the page they originally tried to reach
        // (stashed as `url.intended` when a guest hit a gated route), falling
        // back to the per-user default landing page.
        return redirect()->intended(route($defaultRoute));
    }

    /**
     * Recover from a callback that cannot be completed: Socialite `pull()`s
     * `state` out of the session on first read, so any replay of the callback
     * URL (refresh, browser Back, a second tab overwriting `state`) fails the
     * check — which used to surface as a 500. Same treatment for a Slack
     * response carrying no user id.
     *
     * An already-authenticated visitor simply continues to where they were
     * headed; a guest restarts the flow once, guarded by the IP-keyed retry
     * cache entry (see RETRY_CACHE_PREFIX's docblock for why not session).
     *
     * @return RedirectResponse
     */
    private function restartLogin(): RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->intended(route('battlefield'));
        }

        $retryKey = $this->retryCacheKey();

        if (Cache::pull($retryKey) === true) {
            return redirect()->route('battlefield')
                ->with('error', 'Slack sign-in did not complete. Please try again.');
        }

        Cache::put($retryKey, true, self::RETRY_TTL_SECONDS);

        // Carry this attempt's flow state into the retry inside the marker
        // itself, rather than leaving it sitting in ide_oauth/ccrc_oauth, and
        // tag it with a token only this specific redirect carries — see
        // RESUME_FLOW's docblock for why both are needed.
        $resumeToken = Str::random(32);

        session()->put(self::RESUME_FLOW, [
            'token' => $resumeToken,
            ...array_filter([
                'ide_oauth' => session('ide_oauth'),
                'ccrc_oauth' => session('ccrc_oauth'),
            ], fn ($value) => $value !== null),
        ]);

        return redirect()->route('slack.login', ['resume' => $resumeToken]);
    }

    /**
     * @return array{state: string, client: string, redirect: string|null}|null
     */
    private function consumeIdeFlowState(): ?array
    {
        $ide = session()->pull('ide_oauth');

        if (! is_array($ide) || ! isset($ide['state']) || ! is_string($ide['state'])) {
            return null;
        }

        return [
            'state' => $ide['state'],
            'client' => is_string($ide['client'] ?? null) ? $ide['client'] : 'vscode',
            'redirect' => is_string($ide['redirect'] ?? null) ? $ide['redirect'] : null,
        ];
    }

    private function redirectToIde(User $user, string $state, string $client, ?string $redirect = null): RedirectResponse
    {
        [$plain] = IdeAccessToken::issueOneTime($user, $state, 120);

        $query = http_build_query(['token' => $plain, 'state' => $state]);

        // Preferred path: a loopback HTTP server inside the IDE. Reliable on every OS and
        // needs no `jetbrains://`/`vscode://` scheme registration (which is unreliable on Linux).
        if ($redirect !== null && $this->isLoopbackUrl($redirect)) {
            $separator = str_contains($redirect, '?') ? '&' : '?';

            return redirect()->away($redirect.$separator.$query);
        }

        // Fallback: OS deep link. `phpstorm` is the JetBrains URI product prefix for PhpStorm.
        $url = $client === 'jetbrains'
            ? "jetbrains://phpstorm/token-slayer?{$query}"
            : "vscode://token-slayer.token-slayer/auth?{$query}";

        return redirect()->away($url);
    }

    /**
     * @return array{state: string}|null
     */
    private function consumeCcrcFlowState(): ?array
    {
        $ccrc = session()->pull('ccrc_oauth');

        if (! is_array($ccrc) || ! isset($ccrc['state']) || ! is_string($ccrc['state'])) {
            return null;
        }

        return ['state' => $ccrc['state']];
    }

    private function redirectToCcrc(User $user, string $state): RedirectResponse
    {
        // Unlike the IDE branch, this one accepts no `redirect` from the query
        // string. The destination comes only from config: isLoopbackUrl() can't
        // cover a hub that isn't on loopback, and widening it to allow that
        // would have loosened the IDE branch too — it exists specifically to
        // close this hole there — so this branch drops the dynamic input
        // entirely instead.
        $callback = config('services.ccrc.callback_url');

        if (! is_string($callback) || $callback === '') {
            // Fail-closed: unconfigured means the feature doesn't exist, not
            // that it redirects somewhere it can't complete.
            return redirect()->route('battlefield')
                ->with('error', 'CC Remote Control is not configured on this server.');
        }

        // Its own token kind, not issueOneTime()'s: a token minted here must
        // never be redeemable on /api/ide/auth/exchange, or the "no bearer"
        // guarantee below is worthless — see ExchangeController's docblock.
        [$plain] = IdeAccessToken::issueOneTimeCcrc($user, $state, 120);

        $separator = str_contains($callback, '?') ? '&' : '?';

        return redirect()->away(
            $callback.$separator.http_build_query(['token' => $plain, 'state' => $state])
        );
    }

    /**
     * Only allow redirecting back to a loopback address, so the IDE callback URL can't be
     * abused as an open redirect to an arbitrary host.
     */
    private function isLoopbackUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'http'
            && in_array($parts['host'] ?? null, ['127.0.0.1', 'localhost'], true);
    }

    /**
     * The cache key restartLogin() uses to track one retry per visitor,
     * scoped by IP rather than session — see RETRY_CACHE_PREFIX's docblock.
     *
     * @return string
     */
    private function retryCacheKey(): string
    {
        return self::RETRY_CACHE_PREFIX.request()->ip();
    }
}
