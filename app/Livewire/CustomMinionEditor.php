<?php

namespace App\Livewire;

use App\Enums\CustomMinionOutcome;
use App\Enums\MinionStageMode;
use App\Exceptions\CustomMinionDuplicateException;
use App\Exceptions\CustomMinionMissingException;
use App\Exceptions\MinionLookUnknownException;
use App\Exceptions\MinionPoolChangedException;
use App\Livewire\Concerns\ActsAsSheetPanel;
use App\Livewire\Concerns\ConfirmsInPlace;
use App\Livewire\Concerns\UploadsPoseSheet;
use App\Models\User;
use App\Services\BossArena;
use App\Services\CustomCharacter\ApplyMinionPool;
use App\Services\CustomCharacter\ConfirmCustomMinion;
use App\Services\CustomCharacter\DiscardCustomMinionDraft;
use App\Services\CustomCharacter\MinionPool;
use App\Services\CustomCharacter\MinionPoolCells;
use App\Services\CustomCharacter\MinionPoolSelection;
use App\Services\CustomCharacter\MinionStage;
use App\Services\CustomCharacter\RemoveCustomMinion;
use App\Services\CustomCharacter\RetargetCustomMinionDraft;
use App\Services\CustomCharacter\StageCustomMinion;
use App\Services\CustomCharacter\StageMinionFromFighter;
use App\Support\CacheKeys;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * The sheet's Minions tab (Character, Fighter | Minions): the pool a player's subagents
 * summon from, and everything they do to it. The five default looks and the player's own
 * minions sit in a grid, each with a tick; picking a cell puts it on the stage on the right
 * with the one thing the player can do with it (replace it, remove it, build a new one into an
 * empty slot, add or discard a preview).
 *
 * Ticking a look is choosing, not saving: the ticks are held in `$pending` (component state, no
 * database write, no revision, no broadcast) until the player presses Save, which sends the whole
 * selection with the hash of every cell it drew and is one write, one revision and one broadcast
 * (see ApplyMinionPool). Reset drops the choice, and so does a refresh from another tab.
 *
 * Every button is a `wire:click` of this component: nothing here waits for a browser event
 * to reach a component that might not be mounted yet. A change that touches the pool tells
 * the page around it with the `custom-minions-changed` browser event (`{pool, changed}`: the
 * pool the swarm now draws from, and the cell that was just added or replaced) so the
 * Profile's mini stage follows without a reload, and a result worth a line is a `sheet-toast`.
 *
 * What is selected, which slot is being replaced, the choice not yet saved and what is awaiting a
 * confirm are locked: the browser changes them only by calling the actions below, which check them.
 */
class CustomMinionEditor extends Component
{
    use ConfirmsInPlace, ActsAsSheetPanel, UploadsPoseSheet;

    /**
     * The cell on the stage: `default:<key>`, `slot:<n>` or `overflow` (the preview that has no
     * slot to sit in). An unknown or vanished cell falls back to the preview's cell, else the
     * first default look, when it is drawn.
     *
     * @var string
     */
    #[Locked]
    public string $selected = '';

    /**
     * The slot whose minion the next build will replace; null when a build adds a minion.
     *
     * @var int|null
     */
    #[Locked]
    public ?int $replacing = null;

    /**
     * The cell the last Add or Replace landed in, so the pool lights it and the stage plays the
     * summon once; cleared by the next thing the player does.
     *
     * @var string|null
     */
    #[Locked]
    public ?string $changed = null;

    /**
     * The ticks the player has chosen and not saved: the default keys left out and the slots left
     * out. Null while the choice is the saved one. Held in the component only; nothing is stored,
     * counted or broadcast until Save.
     *
     * @var array{defaults_off: list<string>, slots_off: list<int>}|null
     */
    #[Locked]
    public ?array $pending = null;

    /**
     * What `Use my fighter` says while the player has no custom fighter to copy.
     *
     * @var string
     */
    private const string NO_FIGHTER_NOTE = 'No custom fighter yet. Make one in the Fighter tab.';

    /**
     * Picks the cell to open on: the one holding a preview waiting for the player, else the first
     * default look.
     *
     * @param  MinionPoolCells  $cells  the pool as the tab draws it
     * @return void
     */
    public function mount(MinionPoolCells $cells): void
    {
        $this->selected = $cells->resolveSelected($cells->get($this->player()), $this->selected);
    }

    /**
     * Validation rules: the shared sheet rule.
     *
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return $this->sheetRules();
    }

    /**
     * Puts a cell of the pool on the stage. A cell that does not exist is drawn as the fallback.
     *
     * @param  string  $cell  `default:<key>`, `slot:<n>` or `overflow`
     * @return void
     */
    public function select(string $cell): void
    {
        $this->resetErrorBag();

        if (preg_match('/^(default:[a-z0-9-]+|slot:[1-9]\d*|overflow)$/', $cell) !== 1) {
            return;
        }

        if ($cell !== $this->selected) {
            $this->replacing = null;
        }

        $this->selected = $cell;
        $this->changed = null;
        $this->confirming = null;
    }

    /**
     * Ticks a look into the choice or out of it. Nothing is saved: the choice waits in `$pending`
     * for Save. Bound to the state the player saw, so asking twice is harmless; a look that is not
     * theirs is turned away and the page is told the pool as it is.
     *
     * @param  string  $look  `default:<key>` or `slot:<n>`
     * @param  bool  $ticked  whether the look should be in the pool
     * @return void
     *
     * @throws MinionLookUnknownException when the look is not a default look or a minion the player holds, handled by run()
     */
    public function toggleTick(string $look, bool $ticked): void
    {
        $this->run(function () use ($look, $ticked): void {
            $user = $this->player();
            $saved = MinionPoolSelection::of($user);
            $chosen = $this->chosen($saved);

            if (preg_match('/^default:(.+)$/', $look, $match) === 1 && in_array($match[1], MinionPoolSelection::defaultKeys(), true)) {
                $next = $chosen->withDefault($match[1], $ticked);
            } elseif (preg_match('/^slot:([1-9]\d*)$/', $look, $match) === 1 && $user->customMinions->contains('slot', (int) $match[1])) {
                $next = $chosen->withSlot((int) $match[1], $ticked);
            } else {
                throw new MinionLookUnknownException;
            }

            $this->pending = $next->sameChoiceAs($saved, $this->heldSlots($user))
                ? null
                : ['defaults_off' => $next->defaultsOff, 'slots_off' => $next->slotsOff];
            $this->changed = null;
        });
    }

    /**
     * Saves the choice: every default look, and every minion the tab drew with the hash it drew,
     * as one change of the pool. Counts once against the per-minute allowance. A tab that drew
     * minions another tab has since changed is turned away, and its choice is kept.
     *
     * @param  array<int|string, mixed>  $hashes  the version hash of each minion the tab drew, by slot
     * @param  ApplyMinionPool  $apply  stores the selection
     * @return void
     */
    public function save(array $hashes, ApplyMinionPool $apply): void
    {
        if (! $this->withinChangeLimit()) {
            return;
        }

        $this->run(function () use ($hashes, $apply): void {
            $chosen = $this->chosen(MinionPoolSelection::of($this->player()));
            $defaults = [];
            $slots = [];

            foreach (MinionPoolSelection::defaultKeys() as $key) {
                $defaults[$key] = $chosen->defaultTicked($key);
            }

            foreach ($hashes as $slot => $hash) {
                $slots[(int) $slot] = ['hash' => is_scalar($hash) ? (string) $hash : '', 'ticked' => $chosen->slotTicked((int) $slot)];
            }

            $written = $apply->execute(auth()->user(), $defaults, $slots);

            $this->pending = null;
            $this->changed = null;
            $this->toast($written ? 'Pool saved.' : 'Pool already saved.');
            $this->announce();
        });
    }

    /**
     * Drops the choice that is not saved: the ticks go back to the saved ones.
     *
     * @return void
     */
    public function resetPending(): void
    {
        $this->resetErrorBag();
        $this->pending = null;
        $this->confirming = null;
    }

    /**
     * Starts replacing one of the player's minions: the next build or `Use my fighter` takes over
     * its slot, and it keeps its slot, name and tick.
     *
     * @param  int  $slot  the slot of a minion the player holds
     * @return void
     */
    public function startReplace(int $slot): void
    {
        $this->resetErrorBag();

        if (! $this->player()->customMinions->contains('slot', $slot)) {
            $this->fail(new CustomMinionMissingException, 'action');

            return;
        }

        $this->replacing = $slot;
        $this->selected = "slot:{$slot}";
        $this->changed = null;
        $this->confirming = null;
        $this->focusOn('cme-upload-');
    }

    /**
     * Stops replacing: the minion stays as it is.
     *
     * @return void
     */
    public function cancelReplace(): void
    {
        $this->replacing = null;
        $this->focusOn('cme-replace-open-');
    }

    /**
     * Builds the chosen sheet into a preview: into the slot being replaced, else into the first
     * empty one. Nothing joins the set until the preview is added.
     *
     * @param  StageCustomMinion  $stage  builds and stages the preview
     * @return void
     */
    public function build(StageCustomMinion $stage): void
    {
        $bytes = $this->takeSheetBytes();

        if ($bytes === null) {
            return;
        }

        try {
            $stage->execute(auth()->user(), $bytes, $this->replacing);
            $this->showPreview();
        } catch (Throwable $exception) {
            $this->fail($exception, 'sheet');
        } finally {
            $this->reset('sheet');
        }
    }

    /**
     * Builds a preview from the player's custom fighter, to look at before it is added like any
     * other. It is aimed at the slot being replaced, else the first empty one.
     *
     * @param  StageMinionFromFighter  $stage  copies the fighter into a preview
     * @return void
     */
    public function useFighter(StageMinionFromFighter $stage): void
    {
        if (! $this->withinChangeLimit()) {
            return;
        }

        $this->run(function () use ($stage): void {
            $stage->execute(auth()->user(), $this->replacing);
            $this->showPreview();
        });
    }

    /**
     * Adds (or replaces with) the previewed minion. Bound to the version the player saw, so a
     * preview replaced in another tab is not added by mistake, and asking twice is harmless: the
     * second answer says it is already there rather than "Added".
     *
     * @param  string  $hash  the version hash of the preview that was shown
     * @param  int|null  $slot  the slot the preview was aimed at when it was shown (null = it adds a minion), so a preview another tab re-aimed is refused
     * @param  ConfirmCustomMinion  $confirm  adds the preview to the set
     * @return void
     */
    public function confirm(string $hash, ?int $slot, ConfirmCustomMinion $confirm): void
    {
        if (! $this->withinChangeLimit()) {
            return;
        }

        $this->run(function () use ($hash, $slot, $confirm): void {
            $result = $confirm->execute(auth()->user(), $hash, $slot);
            $look = "slot:{$result->minion->slot}";

            $this->selected = $look;
            $this->replacing = null;
            $this->focusOn('cme-pick-'.$look.'-');

            if ($result->outcome === CustomMinionOutcome::AlreadyInSet) {
                $this->changed = null;
                $this->toast((new CustomMinionDuplicateException)->getMessage());

                return;
            }

            $name = $result->minion->name ?? "Minion {$result->minion->slot}";

            if ($result->outcome === CustomMinionOutcome::Added) {
                $this->forgetPendingSlot($result->minion->slot);
            }

            $this->changed = $look;
            $this->toast(($result->outcome === CustomMinionOutcome::Replaced ? 'Replaced ' : 'Added ').$name);
            $this->announce($look);
        });
    }

    /**
     * Aims the previewed minion at one of the player's slots, for when the set filled up under
     * a preview that was going to be added: it then replaces that minion instead.
     *
     * @param  string  $hash  the version hash of the preview that was shown
     * @param  int  $slot  the slot of a minion the player holds
     * @param  RetargetCustomMinionDraft  $retarget  changes the preview's target
     * @return void
     */
    public function retarget(string $hash, int $slot, RetargetCustomMinionDraft $retarget): void
    {
        if (! $this->withinChangeLimit()) {
            return;
        }

        $this->run(function () use ($hash, $slot, $retarget): void {
            $retarget->execute(auth()->user(), $hash, $slot);
            $this->selected = "slot:{$slot}";
            $this->changed = null;
            $this->focusOn('cme-primary-');
        });
    }

    /**
     * Throws the previewed minion away. The set is unchanged. Bound to the version the player
     * saw, so a preview built in another tab since is not thrown away by mistake.
     *
     * @param  string  $hash  the version hash of the preview that was shown
     * @param  DiscardCustomMinionDraft  $discard  removes the preview
     * @return void
     */
    public function discard(string $hash, DiscardCustomMinionDraft $discard): void
    {
        if (! $this->withinChangeLimit()) {
            return;
        }

        $this->run(function () use ($hash, $discard): void {
            $discard->execute(auth()->user(), $hash);
            $this->changed = null;
            $this->toast('Preview discarded.');
            $this->focusOn('cme-pick-'.$this->selected.'-');
        });
    }

    /**
     * Removes one of the player's minions and its files. Only a slot the player holds resolves,
     * and only the minion the cell showed: the hash the player saw must still be the one in the
     * slot, so a cell another tab has just replaced is not removed by mistake. The slot is then
     * empty, and is what the stage shows. The toast says the minion was removed only when this
     * call removed it.
     *
     * @param  int  $slot  the minion's slot
     * @param  string  $hash  the version hash of the minion the cell showed
     * @param  RemoveCustomMinion  $remove  removes the minion
     * @return void
     *
     * @throws CustomMinionMissingException when the player holds no minion in that slot, handled by run()
     * @throws MinionPoolChangedException when the slot holds another minion than the one shown, handled by run()
     */
    public function removeMinion(int $slot, string $hash, RemoveCustomMinion $remove): void
    {
        if (! $this->withinChangeLimit()) {
            return;
        }

        $this->run(function () use ($slot, $hash, $remove): void {
            $minion = auth()->user()->customMinions()->where('slot', $slot)->first() ?? throw new CustomMinionMissingException;

            if ($minion->version_hash !== $hash) {
                throw new MinionPoolChangedException;
            }

            $name = $minion->name ?? "Minion {$minion->slot}";
            $removed = $remove->execute($minion);

            $this->selected = "slot:{$slot}";
            $this->replacing = null;
            $this->changed = null;

            if ($removed) {
                $this->forgetPendingSlot($slot);
                $this->toast("{$name} removed");
            }

            $this->announce();
            $this->focusOn('cme-pick-'.$this->selected.'-');
        });
    }

    /**
     * Re-renders after the pool changed somewhere else (an admin removed a minion, or another
     * tab changed it): the sheet hears the battlefield broadcast and asks for this. The choice
     * that was not saved goes back to the saved ticks, since it was made against a pool that is gone.
     *
     * @return void
     */
    #[On('custom-minions-refresh')]
    public function refresh(): void
    {
        $this->pending = null;
    }

    /**
     * Renders the tab with the player's pool, what is on the stage and what can be done with it.
     *
     * @param  MinionPoolCells  $cells  the pool as the tab draws it
     * @return View
     */
    public function render(MinionPoolCells $cells): View
    {
        $user = $this->player();
        $saved = MinionPoolSelection::of($user);
        $chosen = $this->chosen($saved);
        $pool = $cells->get($user, $chosen);
        $selected = $cells->resolveSelected($pool, $this->selected);
        $stage = $cells->stageOf($pool, $selected);

        return view('livewire.custom-minion-editor', [
            'pool' => $pool,
            'selected' => $selected,
            'stage' => $stage,
            'unsaved' => ! $chosen->sameChoiceAs($saved, $this->heldSlots($user)),
            'stageUnsaved' => $this->stageUnsaved($stage, $saved),
            'replacingSelected' => $stage->mode === MinionStageMode::Minion && $this->replacing === $stage->slot,
            'confirmingSelected' => $stage->mode === MinionStageMode::Minion && $this->confirming === 'slot-'.$stage->slot,
            'fighterOffer' => $this->fighterOffer($user),
            'fieldFighter' => [
                'key' => $user->equippedFighterKey(app(BossArena::class)->current()->id),
                // the sheet's own payload (with the name and author): the stage registers it under the shared `custom` roster key
                'custom' => $user->customCharacter?->active ? $user->customCharacter->sheetState() : null,
            ],
            'changed' => $this->changed,
        ]);
    }

    /**
     * @inheritDoc
     */
    protected function loadingLabel(): string
    {
        return 'Loading your minions';
    }

    /**
     * @inheritDoc
     */
    protected function confirmPattern(): string
    {
        return '/^slot-[1-9]\d*$/';
    }

    /**
     * @inheritDoc
     */
    protected function confirmOpener(): string
    {
        return 'cme-remove-open-';
    }

    /**
     * Tells the page around the tab what the pool is now (`custom-minions-changed`), and the cell
     * that was just added or replaced, if one was. Also sent after a refusal, so a pool the server
     * did not take is shown as it is and a preview that vanished is dropped.
     *
     * @param  string|null  $changed  `slot:<n>` of the cell just added or replaced
     * @return void
     */
    protected function announce(?string $changed = null): void
    {
        $this->dispatch('custom-minions-changed', pool: app(MinionPool::class)->get($this->player()), changed: $changed);
    }

    /**
     * The signed-in player with what the tab draws from loaded afresh, so nothing a service
     * changed earlier in the request is read from a stale relation (and so each render costs
     * three queries, not one per cell).
     *
     * @return User
     */
    private function player(): User
    {
        return auth()->user()->fresh(['customMinions', 'customMinionDraft', 'customCharacter']);
    }

    /**
     * Runs a change. A change the player is not allowed or able to make shows its reason instead.
     *
     * @param  callable  $change  the change; says its own result (toast, announce)
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
        }
    }

    /**
     * Counts one change of the pool against the player's per-minute allowance: each save, copy,
     * add, discard, removal and retarget is a request that can end in a broadcast to everyone on
     * the field. Choosing ticks is not counted: it stays in the component.
     *
     * @return bool whether the change may go ahead
     */
    private function withinChangeLimit(): bool
    {
        return $this->withinLimit(
            CacheKeys::customMinionChange(auth()->id()),
            (int) config('token_slayer.custom_character.minion_changes_per_minute'),
            60,
            'action',
            'changes',
        );
    }

    /**
     * Moves the stage to the cell the new preview sits in and says it is ready.
     *
     * @return void
     */
    private function showPreview(): void
    {
        $user = $this->player();
        $cells = app(MinionPoolCells::class);
        $pool = $cells->get($user, $this->chosen(MinionPoolSelection::of($user)));
        $this->selected = $cells->resolveSelected($pool, $this->selected, preferPreview: true);
        $this->replacing = null;
        $this->changed = null;
        $this->toast('Preview ready.');
        $this->focusOn('cme-primary-');
    }

    /**
     * The selection the player is looking at: the saved one, with the ticks they chose and have
     * not saved laid over it.
     *
     * @param  MinionPoolSelection  $saved  the selection stored for the player
     * @return MinionPoolSelection
     */
    private function chosen(MinionPoolSelection $saved): MinionPoolSelection
    {
        if ($this->pending === null) {
            return $saved;
        }

        return $saved->withChoice($this->pending['defaults_off'] ?? [], $this->pending['slots_off'] ?? []);
    }

    /**
     * The slots that hold a minion.
     *
     * @param  User  $user  the player, with `customMinions` loaded
     * @return list<int>
     */
    private function heldSlots(User $user): array
    {
        return $user->customMinions->pluck('slot')->map(fn (mixed $slot): int => (int) $slot)->values()->all();
    }

    /**
     * Stops leaving a slot out of the unsaved choice, for a minion that was just added there or
     * removed from it: the slot's tick starts again from the saved selection (which the write dropped
     * it from).
     *
     * @param  int  $slot  the slot
     * @return void
     */
    private function forgetPendingSlot(int $slot): void
    {
        if ($this->pending !== null) {
            $this->pending['slots_off'] = array_values(array_diff($this->pending['slots_off'] ?? [], [$slot]));
        }
    }

    /**
     * Whether the look on the stage is ticked differently from what is saved.
     *
     * @param  MinionStage  $stage  what the stage shows
     * @param  MinionPoolSelection  $saved  the selection stored for the player
     * @return bool
     */
    private function stageUnsaved(MinionStage $stage, MinionPoolSelection $saved): bool
    {
        return match ($stage->mode) {
            MinionStageMode::Default => $saved->defaultTicked(Str::after($stage->look, 'default:')) !== $stage->ticked,
            MinionStageMode::Minion => $saved->slotTicked((int) $stage->slot) !== $stage->ticked,
            default => false,
        };
    }

    /**
     * Whether `Use my fighter` can be used, and if not, why. A fighter already in the set is
     * reported as such (the same words the server uses when it refuses the copy).
     *
     * @param  User  $user  the player, with `customCharacter` and `customMinions` loaded
     * @return array{reason: ?string}
     */
    private function fighterOffer(User $user): array
    {
        $fighter = $user->customCharacter;

        $reason = match (true) {
            $fighter === null => self::NO_FIGHTER_NOTE,
            $user->customMinions->contains('version_hash', $fighter->version_hash) => (new CustomMinionDuplicateException)->getMessage(),
            default => null,
        };

        return ['reason' => $reason];
    }

    /**
     * Moves focus to an element of this component once it has rendered. The id is a constant
     * prefix and the cell token, then the component's own id; nothing the player typed.
     *
     * @param  string  $prefix  the id up to the component id (`cme-primary-`)
     * @return void
     */
    private function focusOn(string $prefix): void
    {
        $this->js('setTimeout(() => document.getElementById('.Js::from($prefix).' + $wire.$id)?.focus(), 0)');
    }
}
