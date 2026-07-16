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
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class SlackController extends Controller
{
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
        $slack = Socialite::driver('slack')->user();

        $existing = User::where('slack_user_id', $slack->getId())->first();

        $attributes = [
            'name' => $slack->getName() ?? $slack->getNickname(),
            'email' => $slack->getEmail() ?? $slack->getId().'@slack.local',
            'slack_handle' => $slack->getNickname(),
            'display_name' => $this->profiles->displayNameFor($slack->getId()) ?? $slack->getName(),
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

        // Send the user back to the page they originally tried to reach
        // (stashed as `url.intended` when a guest hit a gated route), falling
        // back to the per-user default landing page.
        return redirect()->intended(route($defaultRoute));
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
