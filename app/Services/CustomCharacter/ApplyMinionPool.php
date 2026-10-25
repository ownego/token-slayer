<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\MinionLookUnknownException;
use App\Exceptions\MinionPoolChangedException;
use App\Models\CustomMinion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Saves the ticks a player chose in the Minions tab: the whole selection in one go, one
 * write, one revision and one broadcast. The selection comes from the browser, so it is
 * checked against what the player really has before anything changes: every default key must
 * be a default look, and the minions the tab drew (slot and version hash) must be exactly
 * the minions the player holds now. A tab that drew an older set (another tab replaced,
 * removed or added a minion since) is turned away instead of ticking a minion it never showed.
 * It runs under the player's lock and tells the battlefield only when something was written.
 */
class ApplyMinionPool
{
    /**
     * Create the action.
     *
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @param  MinionPoolWriter  $pool  writes the pool's selection and moves its revision
     * @param  AnnounceCustomMinions  $announce  tells the battlefield
     * @return void
     */
    public function __construct(
        private readonly CustomCharacterLock $lock,
        private readonly MinionPoolWriter $pool,
        private readonly AnnounceCustomMinions $announce,
    ) {}

    /**
     * Stores the selection. A selection that matches the one already stored changes nothing:
     * no write, no revision bump, no broadcast.
     *
     * @param  User  $user  the player
     * @param  array<string, bool>  $defaults  every default look's key, whether it should be in the pool
     * @param  array<int, array{hash: string, ticked: bool}>  $slots  every minion the tab showed, by slot: the version hash it showed and whether it should be in the pool
     * @return bool whether the pool was written
     *
     * @throws MinionLookUnknownException when a default key is not a default look, or one is missing
     * @throws MinionPoolChangedException when the minions shown are not the minions the player holds now
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user, array $defaults, array $slots): bool
    {
        $this->checkDefaults($defaults);

        $written = $this->lock->within($user->id, fn (): bool => DB::transaction(function () use ($user, $defaults, $slots): bool {
            $held = CustomMinion::query()->where('user_id', $user->id)->pluck('version_hash', 'slot')->all();

            $this->checkSlots($held, $slots);

            $defaultsOff = array_keys(array_filter($defaults, fn (bool $ticked): bool => ! $ticked));
            $slotsOff = array_keys(array_filter($slots, fn (array $slot): bool => ! $slot['ticked']));

            return $this->pool->write($user->id, function (MinionPoolSelection $stored) use ($defaultsOff, $slotsOff, $held): ?MinionPoolSelection {
                $next = $stored->withChoice($defaultsOff, $slotsOff);

                return $next->sameChoiceAs($stored, array_keys($held)) ? null : $next;
            });
        }));

        if ($written) {
            $this->announce->execute($user);
        }

        return $written;
    }

    /**
     * Refuses a selection that does not name exactly the five default looks.
     *
     * @param  array<string, bool>  $defaults  default key => ticked
     * @return void
     *
     * @throws MinionLookUnknownException when a key is not a default look, or a default look is missing
     */
    private function checkDefaults(array $defaults): void
    {
        $keys = array_map('strval', array_keys($defaults));
        $known = MinionPoolSelection::defaultKeys();

        if (array_diff($keys, $known) !== [] || array_diff($known, $keys) !== []) {
            throw new MinionLookUnknownException;
        }
    }

    /**
     * Refuses a selection drawn from a different set of minions than the player holds.
     *
     * @param  array<int, string>  $held  version hash by slot, as the player holds them now
     * @param  array<int, array{hash: string, ticked: bool}>  $shown  version hash by slot, as the tab drew them
     * @return void
     *
     * @throws MinionPoolChangedException when the slots or the hashes differ
     */
    private function checkSlots(array $held, array $shown): void
    {
        $shownHashes = array_map(fn (array $slot): string => $slot['hash'], $shown);

        ksort($held);
        ksort($shownHashes);

        if ($held !== $shownHashes) {
            throw new MinionPoolChangedException;
        }
    }
}
