<?php

namespace App\Livewire\Concerns;

use App\Exceptions\BlankStripException;
use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterDraftMissingException;
use App\Exceptions\CustomCharacterStorageException;
use App\Exceptions\CustomCharacterVersionException;
use App\Exceptions\CustomMinionDraftMissingException;
use App\Exceptions\CustomMinionDuplicateException;
use App\Exceptions\CustomMinionMissingException;
use App\Exceptions\CustomMinionNoFighterException;
use App\Exceptions\CustomMinionSetFullException;
use App\Exceptions\InvalidPoseSheetException;
use App\Exceptions\MinionLookUnknownException;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * What the fighter editor and the minion editor share: both take a pose sheet through the
 * same pipeline, so they share its upload rule, the hourly allowance, the one list of
 * failures a player can act on, the toast, the loading placeholder and the in-place confirm
 * question. Each editor supplies what differs (its confirm question, what it tells the
 * page after a change).
 *
 * Method names avoid `upload`, `removeUpload`, `uploadMultiple` and `cancelUpload`: in the
 * browser those are Livewire's own file-upload functions and win over a component method of
 * the same name.
 */
trait UploadsPoseSheet
{
    use WithFileUploads;

    /**
     * The pose sheet chosen in the form, before it is built.
     *
     * @var TemporaryUploadedFile|null
     */
    public $sheet = null;

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
     * The validation rule of the `sheet` field. The byte limit is the one the decoder
     * enforces (this only fails fast, before the file is read).
     *
     * @return array<string, array<int, string>>
     */
    protected function sheetRules(): array
    {
        $maxKilobytes = intdiv((int) config('token_slayer.custom_character.max_bytes'), 1024);

        return [
            'sheet' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', "max:{$maxKilobytes}"],
        ];
    }

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
     * Validates the chosen sheet, takes one from the player's hourly build allowance and
     * reads it. Null means the player was turned away and told why, under the file box.
     *
     * @return string|null the sheet's bytes, or null when it was refused
     */
    protected function takeSheetBytes(): ?string
    {
        $this->resetErrorBag();
        $this->validateOnly('sheet');

        $user = auth()->user();

        if (! $this->withinLimit(CacheKeys::customCharacterUpload($user->id), (int) config('token_slayer.custom_character.uploads_per_hour'), 3600, 'sheet', 'uploads')) {
            return null;
        }

        return (string) file_get_contents($this->sheet->getRealPath());
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

    /**
     * Shows why a change did not happen: the sheet's own problem under the file box, any
     * other failure the player can act on as a message (also sent to the sheet as
     * `sheet-error`). Anything unexpected is let through, not hidden behind a friendly line.
     * This list is the one place that decides which failures a player gets to read.
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

        if ($exception instanceof CustomCharacterBusyException
            || $exception instanceof CustomCharacterStorageException
            || $exception instanceof CustomCharacterDraftMissingException
            || $exception instanceof CustomCharacterVersionException
            || $exception instanceof CustomMinionSetFullException
            || $exception instanceof CustomMinionDuplicateException
            || $exception instanceof CustomMinionDraftMissingException
            || $exception instanceof CustomMinionMissingException
            || $exception instanceof CustomMinionNoFighterException
            || $exception instanceof MinionLookUnknownException
            || $exception instanceof BlankStripException) {
            $this->addError($field, $exception->getMessage());
            $this->dispatch('sheet-error', message: $exception->getMessage());
            $this->announce();

            return;
        }

        throw $exception;
    }
}
