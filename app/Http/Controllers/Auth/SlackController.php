<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\IdeAccessToken;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class SlackController extends Controller
{
    public function redirect(Request $request): SymfonyRedirectResponse
    {
        if ($request->query('return') === 'ide' && is_string($state = $request->query('state'))) {
            $client = $request->query('client');
            session()->put('ide_oauth', [
                'state' => $state,
                'client' => is_string($client) ? $client : 'vscode',
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
            'display_name' => $slack->getName(),
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
            return $this->redirectToIde($user, $ide['state'], $ide['client']);
        }

        return redirect()->route($defaultRoute);
    }

    /**
     * @return array{state: string, client: string}|null
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
        ];
    }

    private function redirectToIde(User $user, string $state, string $client): RedirectResponse
    {
        [$plain] = IdeAccessToken::issueOneTime($user, $state, 120);

        $query = http_build_query(['token' => $plain, 'state' => $state]);

        $url = $client === 'jetbrains'
            ? "jetbrains://php-storm/token-slayer?{$query}"
            : "vscode://token-slayer.token-slayer/auth?{$query}";

        return redirect()->away($url);
    }
}
