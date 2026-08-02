<?php

namespace App\Livewire;

use App\Services\HookTokenRotator;
use App\Services\InstallCommandPresenter;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Step-by-step install wizard covering all three ways to feed usage into
 * token-slayer (CLI hooks, browser chat tracker, Cowork watcher). Track and
 * step navigation live entirely in Alpine.js on the client — every branch's
 * content is already rendered server-side; nothing about which one is
 * visible is server state. The only server action is minting/rotating the
 * hook token.
 */
class Setup extends Component
{
    /**
     * @var string|null the freshly minted token, shown once
     */
    public ?string $plainToken = null;

    /**
     * Mints a fresh token for this machine's install command. Only reachable
     * from the "first machine" / "reinstalling here" branches of step 4 — the
     * "token lives on another machine" branch pastes an existing value instead
     * of calling this, since regenerating would 401 the other machine.
     *
     * @param  HookTokenRotator  $rotator
     * @return void
     */
    public function generateToken(HookTokenRotator $rotator): void
    {
        $this->plainToken = $rotator->rotate(auth()->user());
    }

    /**
     * Shapes every install-command variant and manual-config snippet the CLI
     * track's steps 5-6 render. The "token lives on another machine" case is
     * built client-side in Alpine from `pastedToken` instead, since that
     * value never touches the server — `$plainToken` falls back to a display
     * placeholder here so the Unix command still renders (with a value the
     * Alpine `.replace()` handler swaps out) before a token has been minted.
     *
     * @return View
     */
    public function render(): View
    {
        $namespace = config('app.hook_namespace');
        $presenter = new InstallCommandPresenter($namespace, $this->plainToken ?? '<your-token>');

        return view('livewire.setup', [
            'namespace' => $namespace,
            'installUnix' => $presenter->cliUnix(route('install-script')),
            'installWinPs' => $presenter->cliWindowsPowerShell(route('install-script-ps1')),
            'installWinCmd' => $presenter->cliWindowsCmd(route('install-script-ps1')),
            'tokenSaveCommand' => $presenter->tokenSave(),
            'tokenPath' => $presenter->tokenPath(),
            'claudeSnippet' => view('partials.claude-snippet', ['baseUrl' => url('/api/events'), 'namespace' => $namespace])->render(),
            'codexSnippet' => view('partials.codex-snippet', ['baseUrl' => url('/api/events').'?provider=codex', 'namespace' => $namespace])->render(),
            'antigravitySnippet' => view('partials.antigravity-snippet', ['baseUrl' => url('/api/events'), 'namespace' => $namespace])->render(),
            'installCowork' => $presenter->cowork(route('cowork-install-script')),
            'userscriptUrl' => route('userscript'),
        ]);
    }
}
