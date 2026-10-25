<?php

namespace App\Livewire\Concerns;

use App\Exceptions\InvalidPoseSheetException;
use App\Exceptions\PlayerFacingException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * What a component shows inside the fighter sheet's panel: the toast line, the loading
 * placeholder, the rate limit refusal and the one place that decides which failures a
 * player gets to read. The fighter editor and the minion editor are both such panels.
 * Each editor supplies what differs (what it tells the page after a change, its loading
 * label).
 */
trait ActsAsSheetPanel
{
    /**
     * What each editor tells the page around it after a change or a refusal, so the sheet
     * follows without a reload.
     *
     * @return void
     */
    abstract protected function announce(): void;

    /**
     * What the panel says to a screen reader while it loads.
     *
     * @return string
     */
    abstract protected function loadingLabel(): string;

    /**
     * Tells the sheet to show a short result line (its toast).
     *
     * @param  string  $message  the line
     * @return void
     */
    protected function toast(string $message): void
    {
        $this->dispatch('sheet-toast', message: $message);
    }

    /**
     * Counts one attempt against a rate limit and, when the player is over it, says so in
     * words that fit the wait ("1 minute", "2 minutes").
     *
     * @param  string  $key  the limiter key
     * @param  int  $max  how many attempts the window allows
     * @param  int  $decaySeconds  the window
     * @param  string  $field  where to show the refusal; a refusal anywhere but under the file box also goes to the sheet as `sheet-error`
     * @param  string  $noun  what the player did too often ("uploads", "changes")
     * @return bool whether the attempt may go ahead
     */
    protected function withinLimit(string $key, int $max, int $decaySeconds, string $field, string $noun): bool
    {
        if (RateLimiter::attempt($key, $max, fn (): bool => true, $decaySeconds)) {
            return true;
        }

        $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
        $message = "Too many {$noun}. Try again in {$minutes} ".Str::plural('minute', $minutes).'.';

        $this->addError($field, $message);

        if ($field !== 'sheet') {
            $this->dispatch('sheet-error', message: $message);
        }

        return false;
    }

    /**
     * Shows why a change did not happen: the sheet's own problem under the file box, any
     * other failure the player can act on as a message (also sent to the sheet as
     * `sheet-error`). Anything unexpected is let through, not hidden behind a friendly line.
     * A failure is one the player can act on when it extends `PlayerFacingException`.
     *
     * @param  Throwable  $exception  what went wrong
     * @param  string  $field  where to show it
     * @return void
     *
     * @throws Throwable when the failure is not one the player can do something about
     */
    protected function fail(Throwable $exception, string $field): void
    {
        if ($exception instanceof InvalidPoseSheetException) {
            $this->addError('sheet', $exception->getMessage().' '.$exception->problem->hint());

            return;
        }

        if ($exception instanceof PlayerFacingException) {
            $this->addError($field, $exception->getMessage());
            $this->dispatch('sheet-error', message: $exception->getMessage());
            $this->announce();

            return;
        }

        throw $exception;
    }

    /**
     * What the panel shows while it loads: it is mounted when the player first opens it.
     *
     * @return string
     */
    public function placeholder(): string
    {
        $label = e($this->loadingLabel());

        return <<<HTML
        <div class="cce-skel" role="status" aria-label="{$label}"><i></i><i></i><i></i></div>
        HTML;
    }
}
