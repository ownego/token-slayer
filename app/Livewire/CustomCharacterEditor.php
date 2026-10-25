<?php

namespace App\Livewire;

use App\Exceptions\CustomCharacterVersionException;
use App\Livewire\Concerns\ActsAsSheetPanel;
use App\Livewire\Concerns\ConfirmsInPlace;
use App\Livewire\Concerns\UploadsPoseSheet;
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
use Livewire\Attributes\On;
use Livewire\Component;
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
 * The upload rule and the hourly allowance (UploadsPoseSheet), the toast, the placeholder and the
 * list of failures a player gets to read (ActsAsSheetPanel) and the in-place confirm question
 * (ConfirmsInPlace) are shared with the minion editor.
 */
class CustomCharacterEditor extends Component
{
    use ConfirmsInPlace, ActsAsSheetPanel, UploadsPoseSheet;

    /**
     * The name box: what the fighter is (or is about to be) called.
     *
     * @var string
     */
    public string $name = '';

    /**
     * Whether the change in flight should play the equip ceremony on the stage
     * (set by `confirm()` only; read and reset by `run()`).
     *
     * @var bool
     */
    private bool $ceremony = false;

    /**
     * Fills the name box from the player's fighter.
     *
     * @return void
     */
    public function mount(): void
    {
        $this->name = (string) auth()->user()->customCharacter()->first()?->name;
    }

    /**
     * Validation rules: the shared sheet rule, and the longest the fighter may be called.
     *
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        $nameMax = (int) config('token_slayer.custom_character.name_max');

        return [
            ...$this->sheetRules(),
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
        $bytes = $this->takeSheetBytes();

        if ($bytes === null) {
            return;
        }

        try {
            $stage->execute(auth()->user(), $bytes);
            $this->toast('Built. It goes live only when you use it.');
            $this->announce();
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
        $this->ceremony = true;
        $this->run(function () use ($hash, $confirm): void {
            $confirm->execute(auth()->user(), $hash);
            $this->toast('Your fighter is equipped.');
            $this->dispatch('sheet-focus', target: 'equipped-slot');
        });
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
            $this->dispatch('sheet-focus', target: 'upload');
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
     *
     * @throws CustomCharacterVersionException when the fighter is already gone, handled by run()
     */
    public function remove(RemoveCustomCharacter $remove): void
    {
        $this->run(function () use ($remove): void {
            $character = auth()->user()->customCharacter()->first() ?? throw new CustomCharacterVersionException;
            Gate::authorize('delete', $character);

            $remove->execute($character);
            $this->name = '';
            $this->toast('Fighter removed. Back to your roster fighter.');
            $this->dispatch('sheet-focus', target: 'opener');
        });
    }

    /**
     * Equips the draft the stage previewed ("Use this fighter"). Takes only the hash;
     * everything acts on the signed-in player, so a forged hash changes nothing.
     *
     * @param  string  $hash  the draft's version hash
     * @param  ConfirmCustomCharacter  $confirm  makes the draft the fighter
     * @return void
     */
    #[On('custom-draft-confirm')]
    public function onDraftConfirm(string $hash, ConfirmCustomCharacter $confirm): void
    {
        $this->confirm($hash, $confirm);
    }

    /**
     * Throws the previewed draft away (the stage's "Discard").
     *
     * @param  DiscardCustomCharacterDraft  $discard  removes the draft
     * @return void
     */
    #[On('custom-draft-discard')]
    public function onDraftDiscard(DiscardCustomCharacterDraft $discard): void
    {
        $this->discard($discard);
    }

    /**
     * Equips a saved version the stage previewed. Only the player's own versions
     * resolve; any other hash is turned away with the `action` error.
     *
     * @param  string  $hash  the version's hash
     * @param  EquipCustomCharacterVersion  $equip  makes the version the fighter
     * @return void
     */
    #[On('custom-version-equip')]
    public function onVersionEquip(string $hash, EquipCustomCharacterVersion $equip): void
    {
        $this->equipVersion($hash, $equip);
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
        $character = $user->customCharacter()->with('versions')->first();

        return view('livewire.custom-character-editor', [
            'draft' => $previews->draftPayload($user),
            'character' => $character === null ? null : [
                'name' => $character->name,
                'active' => $character->active,
                'versions' => $character->versions->map(fn ($version, int $index): array => [
                    'hash' => $version->version_hash,
                    'number' => $index + 1,
                    'current' => $version->version_hash === $character->version_hash,
                    'at' => $version->created_at,
                    'idle' => $previews->execute($user->id, $version->version_hash, $version->animations)[0],
                    'spec' => $previews->spec($user->id, $version->version_hash, $version->animations, staged: false, name: $character->name),
                ])->reverse()->values()->all(),
            ],
        ]);
    }

    /**
     * @inheritDoc
     */
    protected function loadingLabel(): string
    {
        return 'Loading your fighter';
    }

    /**
     * @inheritDoc
     */
    protected function confirmPattern(): string
    {
        return '/^(remove|version-[a-f0-9]{12})$/';
    }

    /**
     * @inheritDoc
     */
    protected function confirmOpener(): string
    {
        return $this->confirming === 'remove' ? 'cce-remove-open-' : 'cce-version-open-'.substr((string) $this->confirming, 8).'-';
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
            $this->ceremony = false;
            $this->fail($exception, 'action');

            return;
        }

        $ceremony = $this->ceremony;
        $this->ceremony = false;
        $this->announce($ceremony);
    }

    /**
     * Tells the page around the editor what the player has now: their fighter, the
     * draft waiting to be used, and which one is equipped. A ceremony plays only
     * after an equip the player asked for.
     *
     * @param  bool  $ceremony  whether the stage should play the equip ceremony
     * @return void
     */
    protected function announce(bool $ceremony = false): void
    {
        $user = auth()->user()->fresh();
        $character = $user->customCharacter()->first();

        $this->dispatch(
            'custom-character-changed',
            custom: $character?->sheetState(),
            draft: app(PreviewStrips::class)->draftPayload($user),
            equipped: $user->equippedFighterKey(app(BossArena::class)->current()->id),
            ceremony: $ceremony,
        );
    }
}
