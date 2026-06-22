<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\IdeAccessToken;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class SlackController extends Controller
{
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

        return Socialite::driver('slack')->scopes(['users:read'])->redirect();
    }

    public function callback(): RedirectResponse
    {
        $slack = Socialite::driver('slack')->user();

        $profile = $this->fetchSlackProfile($slack);

        $existing = User::where('slack_user_id', $slack->getId())->first();

        $attributes = [
            'name' => $slack->getName() ?? $slack->getNickname(),
            'email' => $slack->getEmail() ?? $slack->getId().'@slack.local',
            'slack_handle' => $profile['username'] ?? $slack->getNickname(),
            'display_name' => $profile['display_name'] ?? $profile['real_name'] ?? $slack->getName(),
            'avatar_url' => $slack->getAvatar(),
        ];

        if ($existing === null) {
            $plainToken = Str::random(48);

            $user = User::create([
                ...$attributes,
                'slack_user_id' => $slack->getId(),
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

        if (($ide = $this->consumeIdeFlowState()) !== null) {
            return $this->redirectToIde($user, $ide['state'], $ide['client'], $ide['redirect']);
        }

        return redirect()->route($defaultRoute);
    }

    /**
     * Fetch the Slack display name + username via the users.info API.
     *
     * "Sign in with Slack" identity scopes only return the real name, so we call
     * users.info (requires the "users:read" user scope) to read the display name
     * the team wants to show. Degrades gracefully to nulls when the scope is
     * missing or the request fails, so login never breaks.
     *
     * @return array{username: ?string, display_name: ?string, real_name: ?string}
     */
    private function fetchSlackProfile(object $slack): array
    {
        $empty = ['username' => null, 'display_name' => null, 'real_name' => null];

        $body = (array) ($slack->accessTokenResponseBody ?? []);
        $token = data_get($body, 'authed_user.access_token') ?: ($slack->token ?? null);

        if (! is_string($token) || $token === '') {
            return $empty;
        }

        try {
            $response = Http::timeout(5)
                ->withToken($token)
                ->get('https://slack.com/api/users.info', ['user' => $slack->getId()]);

            if (! $response->successful() || $response->json('ok') !== true) {
                return $empty;
            }

            $user = (array) $response->json('user');

            return [
                'username' => $user['name'] ?? null,
                'display_name' => data_get($user, 'profile.display_name') ?: null,
                'real_name' => data_get($user, 'profile.real_name') ?: null,
            ];
        } catch (\Throwable) {
            return $empty;
        }
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
