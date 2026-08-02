<?php

namespace App\Livewire;

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
     * @return View
     */
    public function render(): View
    {
        return view('livewire.setup');
    }
}
