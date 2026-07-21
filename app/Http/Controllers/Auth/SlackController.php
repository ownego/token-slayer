<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\IdeAccessToken;
use App\Models\User;
use App\Services\Slack\SlackProfileFetcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class SlackController extends Controller
{
    /**
     * Session key marking that this visitor already had one OAuth round trip
     * restarted, so a permanently broken session can't ping-pong forever.
     *
     * @var string
     */
    private const string RETRY_FLAG = 'slack_login_retried';

    public function __construct(private SlackProfileFetcher $profiles) {}

    public function redirect(Request $request): SymfonyRedirectResponse
    {
        if ($request->query('return') === 'ide' && is_string($state = $request->query('state'))) {
            $client = $request->query('client');
            $redirect = $request->query('redirect');
            session()->put('ide_oauth', [
                'state' => $state,
                'client' => $client === 'jetbrains' ? 'jetbrains' : 'vscode',
                'redirect' => is_string($redirect) && $this->isLoopbackUrl($redirect) ? $redirect : null,
            ]);
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

        auth()->login($user);
        session()->forget(self::RETRY_FLAG);

        if (($ide = $this->consumeIdeFlowState()) !== null) {
            return $this->redirectToIde($user, $ide['state'], $ide['client'], $ide['redirect']);
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
     * headed; a guest restarts the flow once, guarded by RETRY_FLAG.
     *
     * @return RedirectResponse
     */
    private function restartLogin(): RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->intended(route('battlefield'));
        }

        if (session()->pull(self::RETRY_FLAG) === true) {
            return redirect()->route('battlefield')
                ->with('error', 'Slack sign-in did not complete. Please try again.');
        }

        session()->put(self::RETRY_FLAG, true);

        return redirect()->route('slack.login');
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
}
