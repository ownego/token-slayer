<?php

namespace App\Livewire;

use App\Services\HookTokenRotator;
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
     * @return View
     */
    public function render(): View
    {
        return view('livewire.setup', [
            'namespace' => config('app.hook_namespace'),
        ]);
    }
}
