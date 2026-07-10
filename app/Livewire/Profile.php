<?php

namespace App\Livewire;

use App\Models\Event;
use App\Models\User;
use App\Services\DamageTotals;
use Illuminate\Support\Str;
use Livewire\Component;

class Profile extends Component
{
    public ?string $plainToken = null;

    public function mount(): void
    {
        $this->plainToken = session()->pull('hook_token_plain');
    }

    public function regenerate(): void
    {
        $plain = Str::random(48);
        auth()->user()->forceFill(['hook_token' => hash('sha256', $plain)])->save();
        $this->plainToken = $plain;
    }

    /**
     * Snapshot of how the user's latest event was attributed, for the status block.
     *
     * @param  User  $user  the profile owner whose latest event is being inspected
     * @return array{event:?Event, clientVersion:?string, latestVersion:string, outdated:bool}
     */
    private function attributionStatus(User $user): array
    {
        $latestVersion = (string) config('token_slayer.client_version');

        return [
            'event' => Event::where('user_id', $user->id)->latest('id')->first(),
            'clientVersion' => $user->client_version,
            'latestVersion' => $latestVersion,
            'outdated' => $user->client_version !== $latestVersion,
        ];
    }

    public function render()
    {
        $namespace = config('app.hook_namespace');
        $envVar = strtoupper($namespace).'_TOKEN';
        $tokenValue = $this->plainToken ?? '<your-token>';
        $tokenPath = "~/.config/{$namespace}/token";

        return view('livewire.profile', [
            'user' => auth()->user(),
            'damageTotals' => app(DamageTotals::class)->forUser(auth()->user()),
            'globalUsage' => app(DamageTotals::class)->global(),
            'accountRows' => app(DamageTotals::class)->forUserByAccount(auth()->user()),
            'attribution' => $this->attributionStatus(auth()->user()),
            'claudeSnippet' => view('partials.claude-snippet', [
                'baseUrl' => url('/api/events'),
                'namespace' => $namespace,
            ])->render(),
            'codexSnippet' => view('partials.codex-snippet', [
                'baseUrl' => url('/api/events').'?provider=codex',
                'namespace' => $namespace,
            ])->render(),
            'antigravitySnippet' => view('partials.antigravity-snippet', [
                'baseUrl' => url('/api/events'),
                'namespace' => $namespace,
            ])->render(),
            'installUrl' => route('install-script'),
            'coworkInstallUrl' => route('cowork-install-script'),
            'userscriptUrl' => route('userscript'),
            'combinedCommand' => 'curl -fsSL '.route('install-script')." | {$envVar}={$tokenValue} sh",
            'coworkCommand' => 'curl -fsSL '.route('cowork-install-script')." | {$envVar}={$tokenValue} sh",
            'tokenSaveCommand' => "mkdir -p ~/.config/{$namespace} && printf '%s' '{$tokenValue}' > {$tokenPath} && chmod 600 {$tokenPath}",
            'tokenPath' => $tokenPath,
            'namespace' => $namespace,
        ]);
    }
}
