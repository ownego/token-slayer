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
            'quotaBars' => fn (array $row): array => $this->quotaBars($row),
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

    /**
     * Shape an account row's 5h/7d utilization into renderable quota-bar
     * descriptors (label, percent, Tailwind band class), skipping buckets the
     * account has never been probed for.
     *
     * @param  array{util_5h:?int, util_7d:?int}  $row  one account row from DamageTotals::forUserByAccount
     * @return array<int, array{label:string, pct:int, band:string}>
     */
    private function quotaBars(array $row): array
    {
        $bars = [];

        foreach (['5h quota' => $row['util_5h'], '7d quota' => $row['util_7d']] as $label => $pct) {
            if ($pct === null) {
                continue;
            }

            $band = match (true) {
                $pct >= 90 => 'bg-red-500',
                $pct >= 70 => 'bg-amber-500',
                default => 'bg-emerald-500',
            };

            $bars[] = ['label' => $label, 'pct' => $pct, 'band' => $band];
        }

        return $bars;
    }
}
