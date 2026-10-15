<?php

namespace App\Livewire;

use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterDraftMissingException;
use App\Exceptions\CustomCharacterStorageException;
use App\Exceptions\CustomCharacterVersionException;
use App\Exceptions\InvalidPoseSheetException;
use App\Models\CustomCharacter;
use App\Services\BossArena;
use App\Services\CustomCharacter\ConfirmCustomCharacter;
use App\Services\CustomCharacter\DeleteCustomCharacterVersion;
use App\Services\CustomCharacter\DiscardCustomCharacterDraft;
use App\Services\CustomCharacter\EquipCustomCharacterVersion;
use App\Services\CustomCharacter\PreviewStrips;
use App\Services\CustomCharacter\RemoveCustomCharacter;
use App\Services\CustomCharacter\RenameCustomCharacter;
use App\Services\CustomCharacter\StageCustomCharacter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Where a player makes and manages their own fighter: upload a pose sheet, see
 * how it came out before it goes live, use or discard it, name the fighter,
 * switch back to an earlier version, delete one, or remove the fighter. It lives
 * in the fighter sheet's Character tab (the `/character` page is only the guide
 * to making a sheet). Every change that touches what is equipped tells the sheet
 * around it (the `custom-character-changed` browser event) so the roster follows
 * without a reload, and a result worth a line is announced as a `sheet-toast`.
 *
 * Method names avoid `upload`, `removeUpload`, `uploadMultiple` and `cancelUpload`:
 * in the browser those are Livewire's own file-upload functions and win over a
 * component method of the same name.
 */
class CustomCharacterEditor extends Component
{
    use WithFileUploads;

    /**
     * The pose sheet chosen in the form, before it is built.
     *
     * @var TemporaryUploadedFile|null
     */
    public $sheet = null;

    /**
     * The name box: what the fighter is (or is about to be) called.
     *
     * @var string
     */
    public string $name = '';

    /**
     * What the player is being asked to confirm, in place: `remove` (the fighter) or
     * `version-<hash>` (deleting one earlier version); null when nothing is pending.
     *
     * @var string|null
     */
    public ?string $confirming = null;

    /**
     * Fills the name box from the player's fighter.
     *
     * @return void
     */
    public function mount(): void
    {
        $this->name = (string) auth()->user()->customCharacter()->value('name');
    }

    /**
     * Validation rules. The sheet's byte limit is the one the decoder enforces
     * (this only fails fast, before the file is read); the name's is the longest
     * the fighter may be called.
     *
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        $maxKilobytes = intdiv((int) config('token_slayer.custom_character.max_bytes'), 1024);
        $nameMax = (int) config('token_slayer.custom_character.name_max');

        return [
            'sheet' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', "max:{$maxKilobytes}"],
            'name' => ['nullable', 'string', "max:{$nameMax}"],
        ];
    }

    /**
     * Builds the chosen sheet into a draft to preview. Nothing goes live: the
     * fighter in use is left alone until the draft is confirmed.
     *
     * @param  StageCustomCharacter  $stage  builds and stages the draft
     * @return void
     */
    public function build(StageCustomCharacter $stage): void
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
            $this->toast('Built. It goes live only when you use it.');
            $this->js('document.getElementById("cce-draft-" + $wire.$id)?.scrollIntoView({ behavior: "smooth", block: "center" })');
        } catch (Throwable $exception) {
            $this->fail($exception, 'sheet');
        } finally {
            $this->reset('sheet');
        }
    }

    /**
     * Makes the previewed draft the player's equipped fighter. Bound to the
     * version the player saw, so a draft replaced in another tab is not confirmed
     * by mistake, and asking twice is harmless.
     *
     * @param  string  $hash  the version hash of the draft that was previewed
     * @param  ConfirmCustomCharacter  $confirm  makes the draft the fighter
     * @return void
     */
    public function confirm(string $hash, ConfirmCustomCharacter $confirm): void
    {
        $this->run(function () use ($hash, $confirm): void {
            $confirm->execute(auth()->user(), $hash);
            $this->toast('Your fighter is equipped.');
        });
    }

    /**
     * Asks for the player's go-ahead before something destructive, in the panel (never a
     * browser dialog). It only remembers the question; the change itself is a separate action.
     *
     * @param  string  $what  `remove` or `version-<hash>`
     * @return void
     */
    public function askToConfirm(string $what): void
    {
        $this->confirming = preg_match('/^(remove|version-[a-f0-9]{12})$/', $what) === 1 ? $what : null;
    }

    /**
     * Drops the question without changing anything, and puts focus back on what opened it.
     *
     * @return void
     */
    public function dismissConfirm(): void
    {
        $opener = $this->confirming === 'remove' ? 'cce-remove-open-' : 'cce-version-open-'.substr((string) $this->confirming, 8).'-';
        $this->confirming = null;
        $this->js('setTimeout(() => document.getElementById("'.$opener.'" + $wire.$id)?.focus(), 0)');
    }

    /**
     * Throws the previewed draft away.
     *
     * @param  DiscardCustomCharacterDraft  $discard  removes the draft
     * @return void
     */
    public function discard(DiscardCustomCharacterDraft $discard): void
    {
        $this->run(function () use ($discard): void {
            $discard->execute(auth()->user());
            $this->toast('Preview discarded. Your fighter is unchanged.');
        });
    }

    /**
     * Gives the fighter the name in the box.
     *
     * @param  RenameCustomCharacter  $rename  renames the fighter
     * @return void
     */
    public function rename(RenameCustomCharacter $rename): void
    {
        $this->validateOnly('name');

        $this->run(function () use ($rename): void {
            $this->name = $rename->execute(auth()->user(), $this->name)->name;
            $this->toast('Name saved.');
        });
    }

    /**
     * Switches the fighter to one of its earlier versions, and equips it.
     *
     * @param  string  $hash  the version hash to switch to
     * @param  EquipCustomCharacterVersion  $equip  switches the version
     * @return void
     */
    public function equipVersion(string $hash, EquipCustomCharacterVersion $equip): void
    {
        $this->run(function () use ($hash, $equip): void {
            $equip->execute(auth()->user(), $hash);
            $this->toast('Switched to that version and equipped it.');
        });
    }

    /**
     * Deletes one of the fighter's earlier versions.
     *
     * @param  string  $hash  the version hash to delete
     * @param  DeleteCustomCharacterVersion  $delete  deletes the version
     * @return void
     */
    public function deleteVersion(string $hash, DeleteCustomCharacterVersion $delete): void
    {
        $this->run(function () use ($hash, $delete): void {
            $delete->execute(auth()->user(), $hash);
            $this->toast('Version deleted.');
        });
    }

    /**
     * Removes the fighter and every version of it. The player goes back to the
     * roster character they had equipped.
     *
     * @param  RemoveCustomCharacter  $remove  removes the fighter
     * @return void
     */
    public function remove(RemoveCustomCharacter $remove): void
    {
        $this->run(function () use ($remove): void {
            $character = auth()->user()->customCharacter()->first() ?? throw new CustomCharacterVersionException;
            Gate::authorize('delete', $character);

            $remove->execute($character);
            $this->name = '';
            $this->toast('Fighter removed. Back to your roster fighter.');
        });
    }

    /**
     * What the panel shows while it loads: it is mounted when the player first opens it.
     *
     * @return string
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <p class="cce-note" role="status">Loading your fighter…</p>
        HTML;
    }

    /**
     * Renders the editor with the player's draft, fighter and versions.
     *
     * @param  PreviewStrips  $previews  describes the strips to preview
     * @return View
     */
    public function render(PreviewStrips $previews): View
    {
        $user = auth()->user();
        $draft = $user->customCharacterDraft()->first();
        $character = $user->customCharacter()->with('versions')->first();

        return view('livewire.custom-character-editor', [
            'draft' => $draft === null ? null : [
                'hash' => $draft->version_hash,
                'spec' => $previews->spec($user->id, $draft->version_hash, $draft->animations, staged: true, name: $character?->name ?? ($this->name ?: (string) config('token_slayer.custom_character.default_name'))),
            ],
            'character' => $character === null ? null : [
                'name' => $character->name,
                'active' => $character->active,
                'versions' => $character->versions->reverse()->map(fn ($version): array => [
                    'hash' => $version->version_hash,
                    'current' => $version->version_hash === $character->version_hash,
                    'at' => $version->created_at,
                    'idle' => $previews->execute($user->id, $version->version_hash, $version->animations)[0],
                ])->values()->all(),
            ],
        ]);
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
     * Runs a change, then tells the page around the editor about it. A change the
     * player is not allowed or able to make shows its reason instead.
     *
     * @param  callable  $change  the change
     * @return void
     */
    private function run(callable $change): void
    {
        $this->confirming = null;
        $this->resetErrorBag();

        try {
            $change();
        } catch (Throwable $exception) {
            $this->fail($exception, 'action');

            return;
        }

        $this->announce();
    }

    /**
     * Shows why a change did not happen: the sheet's own problem under the file
     * box, anything else the player can act on as a general message. Anything
     * unexpected is let through, not hidden behind a friendly line.
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
            $this->addError('sheet', $exception->getMessage());

            return;
        }

        if ($exception instanceof CustomCharacterBusyException
            || $exception instanceof CustomCharacterStorageException
            || $exception instanceof CustomCharacterDraftMissingException
            || $exception instanceof CustomCharacterVersionException) {
            $this->addError($field, $exception->getMessage());

            return;
        }

        throw $exception;
    }

    /**
     * Tells the page around the editor what the player's fighter is now, so the
     * fighter sheet's roster follows without a reload.
     *
     * @return void
     */
    private function announce(): void
    {
        $user = auth()->user()->fresh();
        $character = $user->customCharacter()->first();

        $this->dispatch(
            'custom-character-changed',
            custom: $character?->sheetState(),
            equipped: $user->equippedFighterKey(app(BossArena::class)->current()->id),
        );
    }
}
