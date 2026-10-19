<?php

namespace App\Livewire;

use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterStorageException;
use App\Exceptions\CustomCharacterVersionException;
use App\Exceptions\CustomMinionDraftMissingException;
use App\Exceptions\CustomMinionDuplicateException;
use App\Exceptions\CustomMinionMissingException;
use App\Exceptions\CustomMinionSetFullException;
use App\Exceptions\InvalidPoseSheetException;
use App\Models\CustomMinion;
use App\Models\User;
use App\Services\CustomCharacter\AddFighterAsMinion;
use App\Services\CustomCharacter\ConfirmCustomMinion;
use App\Services\CustomCharacter\DiscardCustomMinionDraft;
use App\Services\CustomCharacter\PreviewStrips;
use App\Services\CustomCharacter\RemoveCustomMinion;
use App\Services\CustomCharacter\StageCustomMinion;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Where a player manages their custom minions, in the fighter sheet's Forge drawer
 * (the Minions tab): upload a pose sheet and preview the result on the stage before
 * it joins the set, copy their custom fighter in, preview or remove one they have.
 * Every change tells the sheet around it (the `custom-minions-changed` browser event
 * with the set and the draft) so the stage and the Profile mini-stage follow without
 * a reload, and a result worth a line is announced as a `sheet-toast`.
 *
 * It shares the fighter editor's upload limit (one counter for both), the same pose
 * sheet contract and the same per-player lock, because a minion is built by the same
 * pipeline as a fighter.
 *
 * Method names avoid `upload`, `removeUpload`, `uploadMultiple` and `cancelUpload`:
 * in the browser those are Livewire's own file-upload functions and win over a
 * component method of the same name.
 */
class CustomMinionEditor extends Component
{
    use WithFileUploads;

    /**
     * The pose sheet chosen in the form, before it is built.
     *
     * @var TemporaryUploadedFile|null
     */
    public $sheet = null;

    /**
     * What the player is being asked to confirm, in place: `minion-<id>` (removing that
     * minion); null when nothing is pending.
     *
     * @var string|null
     */
    public ?string $confirming = null;

    /**
     * Validation rules, the same as the fighter editor's sheet rule: the byte limit is the one
     * the decoder enforces (this only fails fast, before the file is read).
     *
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        $maxKilobytes = intdiv((int) config('token_slayer.custom_character.max_bytes'), 1024);

        return [
            'sheet' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', "max:{$maxKilobytes}"],
        ];
    }

    /**
     * Builds the chosen sheet into a draft to preview. Nothing joins the set until the draft
     * is added.
     *
     * @param  StageCustomMinion  $stage  builds and stages the draft
     * @return void
     */
    public function build(StageCustomMinion $stage): void
    {
        $this->resetErrorBag();
        $this->validateOnly('sheet');

        $user = auth()->user();
        $key = "custom-character-upload:{$user->id}";

        if (! RateLimiter::attempt($key, (int) config('token_slayer.custom_character.uploads_per_hour'), fn (): bool => true, 3600)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            $this->addError('sheet', "Too many uploads. Try again in {$minutes} minutes.");

            return;
        }

        try {
            $stage->execute($user, (string) file_get_contents($this->sheet->getRealPath()));
            $this->announce();
            $this->toast('Built. Add it to your minions to use it.');
        } catch (Throwable $exception) {
            $this->fail($exception, 'sheet');
        } finally {
            $this->reset('sheet');
        }
    }

    /**
     * Adds the previewed draft to the set. Bound to the version the player saw, so a draft
     * replaced in another tab is not added by mistake, and asking twice is harmless.
     *
     * @param  string  $hash  the version hash of the draft that was previewed
     * @param  ConfirmCustomMinion  $confirm  adds the draft to the set
     * @return void
     */
    public function confirm(string $hash, ConfirmCustomMinion $confirm): void
    {
        $this->run(function () use ($hash, $confirm): string {
            $confirm->execute(auth()->user(), $hash);

            return 'Added to your minions';
        });
    }

    /**
     * Throws the previewed draft away. The set is unchanged. Bound to the version the player
     * saw, so a draft built in another tab since is not thrown away by mistake.
     *
     * @param  string  $hash  the version hash of the draft that was previewed
     * @param  DiscardCustomMinionDraft  $discard  removes the draft
     * @return void
     */
    public function discard(string $hash, DiscardCustomMinionDraft $discard): void
    {
        $this->run(function () use ($hash, $discard): string {
            $discard->execute(auth()->user(), $hash);

            return 'Preview discarded. Your minions are unchanged.';
        }, focus: 'minions-first');
    }

    /**
     * Copies the player's custom fighter into the set, with no draft: they have already seen it.
     *
     * @param  AddFighterAsMinion  $add  adds the fighter's copy
     * @return void
     */
    public function addFighter(AddFighterAsMinion $add): void
    {
        $this->run(function () use ($add): string {
            $add->execute(auth()->user());

            return 'Added to your minions';
        });
    }

    /**
     * Asks for the player's go-ahead before removing a minion, in the panel (never a browser
     * dialog). It only remembers the question; the removal is a separate action.
     *
     * @param  string  $what  `minion-<id>`
     * @return void
     */
    public function askToConfirm(string $what): void
    {
        $this->confirming = preg_match('/^minion-\d+$/', $what) === 1 ? $what : null;
    }

    /**
     * Drops the question without changing anything, and puts focus back on what opened it.
     *
     * @return void
     */
    public function dismissConfirm(): void
    {
        $opener = 'cme-remove-open-'.substr((string) $this->confirming, 7).'-';
        $this->confirming = null;
        $this->js('setTimeout(() => document.getElementById("'.$opener.'" + $wire.$id)?.focus(), 0)');
    }

    /**
     * Removes one of the player's minions and its files. Only the player's own minions
     * resolve; anything else is turned away as a minion that is not available.
     *
     * @param  int  $id  the minion's id
     * @param  RemoveCustomMinion  $remove  removes the minion
     * @return void
     *
     * @throws CustomMinionMissingException when the player has no such minion, handled by run()
     */
    public function removeMinion(int $id, RemoveCustomMinion $remove): void
    {
        $this->run(function () use ($id, $remove): string {
            $minion = auth()->user()->customMinions()->whereKey($id)->first() ?? throw new CustomMinionMissingException;

            $remove->execute($minion);

            return 'Minion removed';
        }, focus: 'minions-first');
    }

    /**
     * Adds the draft the stage previewed ("Add to minions"). Takes only the hash;
     * everything acts on the signed-in player, so a forged hash changes nothing. It always
     * answers the stage, which waits for it: with `custom-minions-changed` (even when the
     * draft was already added) or with `sheet-error`.
     *
     * @param  string  $hash  the draft's version hash
     * @param  ConfirmCustomMinion  $confirm  adds the draft to the set
     * @return void
     */
    #[On('custom-minion-draft-confirm')]
    public function onDraftConfirm(string $hash, ConfirmCustomMinion $confirm): void
    {
        $this->confirm($hash, $confirm);
    }

    /**
     * Throws the previewed draft away (the stage's "Discard"). Takes the hash like the add does:
     * a draft that was replaced meanwhile is kept and the stage hears `sheet-error`.
     *
     * @param  string  $hash  the draft's version hash
     * @param  DiscardCustomMinionDraft  $discard  removes the draft
     * @return void
     */
    #[On('custom-minion-draft-discard')]
    public function onDraftDiscard(string $hash, DiscardCustomMinionDraft $discard): void
    {
        $this->discard($hash, $discard);
    }

    /**
     * Re-renders after the set changed somewhere else (an admin removed a minion, or another
     * tab changed it): the sheet hears the battlefield broadcast and asks for this.
     *
     * @return void
     */
    #[On('custom-minions-refresh')]
    public function refresh(): void
    {
        // the render does the work
    }

    /**
     * What the panel shows while it loads: it is mounted when the player first opens it.
     *
     * @return string
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="cce-skel" role="status" aria-label="Loading your minions"><i></i><i></i><i></i></div>
        HTML;
    }

    /**
     * Renders the editor with the player's minions, draft and fighter offer.
     *
     * @param  PreviewStrips  $previews  describes the draft to preview
     * @return View
     */
    public function render(PreviewStrips $previews): View
    {
        $user = auth()->user();
        $minions = $user->customMinions()->get();
        $max = (int) config('token_slayer.custom_character.minions_max');
        $full = $minions->count() >= $max;

        return view('livewire.custom-minion-editor', [
            'cards' => $minions->values()->map(fn (CustomMinion $minion, int $index): array => $this->card($minion, $index))->all(),
            'max' => $max,
            'full' => $full,
            'fullMessage' => (new CustomMinionSetFullException)->getMessage(),
            'draft' => $previews->minionDraftPayload($user),
            'fighterOffer' => $this->fighterOffer($user, $minions->pluck('version_hash')->all(), $full),
        ]);
    }

    /**
     * One minion as its card shows it: the idle loop to play and the spec the Preview button hands the stage.
     *
     * @param  CustomMinion  $minion  the minion
     * @param  int  $index  its place in the set, from 0
     * @return array{id: int, number: int, source: string, idle: array{url: string, frames: int, rate: int}, spec: array<string, mixed>}
     */
    private function card(CustomMinion $minion, int $index): array
    {
        $spec = $minion->sheetState();

        return [
            'id' => $minion->id,
            'number' => $index + 1,
            'source' => $spec['source'],
            'idle' => [
                'url' => "{$spec['url_base']}/idle.png",
                'frames' => $spec['animations']['idle']['frames'],
                'rate' => $spec['animations']['idle']['rate'],
            ],
            'spec' => $spec,
        ];
    }

    /**
     * Whether `Add my fighter` is offered, and if it can be used. Null when the player has no
     * custom fighter (the button is not shown at all); otherwise the reason it is disabled, or
     * null in `reason` when it can be used. Same order as AddFighterAsMinion: a fighter already
     * in the set is reported before a full set.
     *
     * @param  User  $user  the signed-in player
     * @param  list<string>  $heldHashes  the version hashes already in the set
     * @param  bool  $full  whether the set is full
     * @return array{reason: ?string}|null
     */
    private function fighterOffer(User $user, array $heldHashes, bool $full): ?array
    {
        $fighter = $user->customCharacter()->first();

        if ($fighter === null) {
            return null;
        }

        $reason = match (true) {
            in_array($fighter->version_hash, $heldHashes, true) => (new CustomMinionDuplicateException)->getMessage(),
            $full => (new CustomMinionSetFullException)->getMessage(),
            default => null,
        };

        return ['reason' => $reason];
    }

    /**
     * Tells the sheet to show a short result line (its toast).
     *
     * @param  string  $message  the line
     * @return void
     */
    private function toast(string $message): void
    {
        $this->dispatch('sheet-toast', message: $message);
    }

    /**
     * Runs a change, then tells the page around the editor about it, then shows its result
     * line. A change the player is not allowed or able to make shows its reason instead.
     *
     * @param  callable  $change  the change; returns the result line
     * @param  string|null  $focus  where focus goes afterwards (a `sheet-focus` target), if anywhere
     * @return void
     */
    private function run(callable $change, ?string $focus = null): void
    {
        $this->confirming = null;
        $this->resetErrorBag();

        try {
            $message = $change();
        } catch (Throwable $exception) {
            $this->fail($exception, 'action');

            return;
        }

        $this->announce();
        $this->toast($message);

        if ($focus !== null) {
            $this->dispatch('sheet-focus', target: $focus);
        }
    }

    /**
     * Shows why a change did not happen: the sheet's own problem under the file box, anything
     * else the player can act on as a message (also sent to the stage as `sheet-error`, which
     * the stage waits for after asking to add a draft). Anything unexpected is let through,
     * not hidden behind a friendly line.
     *
     * @param  Throwable  $exception  what went wrong
     * @param  string  $field  where to show it
     * @return void
     *
     * @throws Throwable when the failure is not one the player can do something about
     */
    private function fail(Throwable $exception, string $field): void
    {
        if ($exception instanceof InvalidPoseSheetException) {
            $this->addError('sheet', $exception->getMessage().' '.$exception->problem->hint());

            return;
        }

        if ($exception instanceof CustomCharacterBusyException
            || $exception instanceof CustomCharacterStorageException
            || $exception instanceof CustomCharacterVersionException
            || $exception instanceof CustomMinionSetFullException
            || $exception instanceof CustomMinionDuplicateException
            || $exception instanceof CustomMinionDraftMissingException
            || $exception instanceof CustomMinionMissingException) {
            $this->addError($field, $exception->getMessage());
            $this->dispatch('sheet-error', message: $exception->getMessage());
            $this->announce();

            return;
        }

        throw $exception;
    }

    /**
     * Tells the page around the editor what the player has now: the set and the draft waiting
     * to be added (`custom-minions-changed`, E1).
     *
     * @return void
     */
    private function announce(): void
    {
        $user = auth()->user()->fresh();

        $this->dispatch(
            'custom-minions-changed',
            minions: $user->customMinions->map(fn (CustomMinion $minion): array => $minion->sheetState())->values()->all(),
            draft: app(PreviewStrips::class)->minionDraftPayload($user),
        );
    }
}
