<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Locked;

/**
 * The in-place confirm question a sheet panel asks before something destructive: in the
 * panel, never a browser dialog. The question only remembers what is being asked; the
 * change itself is a separate action of the component. Each editor supplies what differs
 * (which tokens it accepts and which button opened the question).
 */
trait ConfirmsInPlace
{
    /**
     * What the player is being asked to confirm, in place (an editor-specific token such as
     * `remove` or `slot-2`); null when nothing is pending. Locked: only `askToConfirm()`
     * sets it, from a pattern the editor owns, so the browser can never put a value of its
     * own here (the value ends up in an element id the confirm row hands focus back to).
     *
     * @var string|null
     */
    #[Locked]
    public ?string $confirming = null;

    /**
     * The pattern a `askToConfirm()` token must match to be remembered.
     *
     * @return string
     */
    abstract protected function confirmPattern(): string;

    /**
     * The id (without the component id that follows it) of the button that opened the
     * pending question, where focus returns when it is dropped.
     *
     * @return string
     */
    abstract protected function confirmOpener(): string;

    /**
     * Asks for the player's go-ahead before something destructive, in the panel (never a
     * browser dialog). It only remembers the question; the change itself is a separate action.
     *
     * @param  string  $what  the editor's token for what would be removed
     * @return void
     */
    public function askToConfirm(string $what): void
    {
        $this->confirming = preg_match($this->confirmPattern(), $what) === 1 ? $what : null;
    }

    /**
     * Drops the question without changing anything, and puts focus back on what opened it.
     *
     * @return void
     */
    public function dismissConfirm(): void
    {
        $opener = $this->confirmOpener();
        $this->confirming = null;
        $this->js('setTimeout(() => document.getElementById('.json_encode($opener, JSON_THROW_ON_ERROR).' + $wire.$id)?.focus(), 0)');
    }
}
