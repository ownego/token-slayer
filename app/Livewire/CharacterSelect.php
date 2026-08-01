<?php

namespace App\Livewire;

use App\Enums\FighterCharacter;
use App\Events\FighterCharacterChanged;
use App\Services\BossArena;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Lets the authenticated user browse and equip one of the fifteen fighter
 * characters. Embedded directly in the battlefield page (no dedicated
 * route) behind an auth-only modal — see resources/views/livewire/battlefield.blade.php.
 */
class CharacterSelect extends Component
{
    /**
     * The character value currently shown as equipped: the user's explicit
     * choice if any, otherwise today's deterministic per-boss assignment.
     * Used ONLY to seed the carousel's starting index on open — for the
     * true persisted equip state, see $equippedKey.
     *
     * @var string
     */
    public string $equipped = '';

    /**
     * The user's explicitly persisted equipped character, or null when the
     * user has never explicitly equipped one (still relying on the
     * deterministic per-boss fallback). Drives the "Equipped"/"Equip"
     * label and disabled state in the modal — unlike $equipped, this is
     * never the deterministic fallback.
     *
     * @var string|null
     */
    public ?string $equippedKey = null;

    /**
     * Resolves the starting equipped value from the current boss context,
     * mirroring App\Livewire\Battlefield::mount()'s use of BossArena, and
     * the true persisted equip state for the modal's equip-status display.
     * Guarded against a null user for defense-in-depth, even though the
     * auth-only Blade wrapper makes this currently unreachable as a guest.
     *
     * @param  BossArena  $arena  supplies the current boss, used only to
     *                            resolve the deterministic fallback for
     *                            display when nothing is equipped yet
     * @return void
     */
    public function mount(BossArena $arena): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $this->equipped = $user->characterForBoss($arena->current()->id);
        $this->equippedKey = $user->equipped_character?->value;
    }

    /**
     * Equips the given character for the authenticated user, persists it,
     * and broadcasts the change so every live viewer re-skins the sprite.
     * Unknown keys are silently rejected — the modal only ever sends keys
     * from characters(), so an unknown key implies a stale/tampered request.
     * Also returns early on a guest/stale session: Livewire snapshots are
     * signed but not session-bound, so an expired or replayed session can
     * reach this method with no authenticated user.
     *
     * @param  string  $key  a FighterCharacter enum value
     * @return void
     */
    public function equip(string $key): void
    {
        $character = FighterCharacter::tryFrom($key);

        if ($character === null) {
            return;
        }

        $user = auth()->user();

        if (! $user) {
            return;
        }

        $user->forceFill(['equipped_character' => $character])->save();
        $this->equipped = $character->value;
        $this->equippedKey = $character->value;

        FighterCharacterChanged::dispatch($user);
    }

    /**
     * All playable fighter character values, in enum-declaration order —
     * the ordered list the modal's carousel renders.
     *
     * @return array<int, string>
     */
    public function characters(): array
    {
        return array_column(FighterCharacter::cases(), 'value');
    }

    /**
     * @return View
     */
    public function render()
    {
        return view('livewire.character-select');
    }
}
