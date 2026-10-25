<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\MinionLookUnknownException;
use App\Models\CustomMinion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ticks or unticks one look in a player's minion pool: a default look
 * (`default:<key>`) or one of their custom minions (`slot:<n>`). The look comes from
 * the browser, so it is checked against what the player really has before anything
 * changes. It runs under the player's lock, bumps the pool revision in the same
 * transaction as the change, and tells the battlefield.
 */
class TickMinionLook
{
    /**
     * Create the action.
     *
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @param  AnnounceCustomMinions  $announce  tells the battlefield
     * @return void
     */
    public function __construct(
        private readonly CustomCharacterLock $lock,
        private readonly AnnounceCustomMinions $announce,
    ) {}

    /**
     * Sets the look's tick. A tick that already matches changes nothing: no revision bump, no broadcast.
     *
     * @param  User  $user  the player
     * @param  string  $look  `default:<key>` or `slot:<n>`
     * @param  bool  $ticked  whether the look should be in the pool
     * @return void
     *
     * @throws MinionLookUnknownException when the look is not a default look or a minion the player holds
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user, string $look, bool $ticked): void
    {
        $changed = $this->lock->within($user->id, fn (): bool => DB::transaction(function () use ($user, $look, $ticked): bool {
            $changed = $this->apply($user, $look, $ticked);

            if ($changed) {
                $user->increment('minion_pool_rev');
            }

            return $changed;
        }));

        if ($changed) {
            // The revision went up in SQL, so the model in hand may be a bump behind another tab's.
            $user->refresh();
            $this->announce->execute($user);
        }
    }

    /**
     * Writes the tick where the look lives.
     *
     * @param  User  $user  the player
     * @param  string  $look  `default:<key>` or `slot:<n>`
     * @param  bool  $ticked  whether the look should be in the pool
     * @return bool whether anything changed
     *
     * @throws MinionLookUnknownException when the look is not the player's
     */
    private function apply(User $user, string $look, bool $ticked): bool
    {
        if (preg_match('/^default:(.+)$/', $look, $match) === 1) {
            return $this->tickDefault($user, $match[1], $ticked);
        }

        if (preg_match('/^slot:([1-9]\d*)$/', $look, $match) === 1) {
            return $this->tickCustom($user, (int) $match[1], $ticked);
        }

        throw new MinionLookUnknownException;
    }

    /**
     * Adds the default look to, or takes it out of, the player's list of un-ticked defaults.
     *
     * @param  User  $user  the player
     * @param  string  $key  the default look's key
     * @param  bool  $ticked  whether the look should be in the pool
     * @return bool whether anything changed
     *
     * @throws MinionLookUnknownException when the key is not one of the default looks
     */
    private function tickDefault(User $user, string $key, bool $ticked): bool
    {
        if (! in_array($key, config('token_slayer.custom_character.default_minions'), true)) {
            throw new MinionLookUnknownException;
        }

        $off = User::query()->whereKey($user->id)->first()?->minion_defaults_off ?? [];

        if (in_array($key, $off, true) === ! $ticked) {
            return false;
        }

        $user->minion_defaults_off = $ticked
            ? array_values(array_diff($off, [$key]))
            : [...$off, $key];
        $user->save();

        return true;
    }

    /**
     * Sets `enabled` on the minion the player holds in the slot.
     *
     * @param  User  $user  the player
     * @param  int  $slot  the slot
     * @param  bool  $ticked  whether the minion should be in the pool
     * @return bool whether anything changed
     *
     * @throws MinionLookUnknownException when the player holds no minion in the slot
     */
    private function tickCustom(User $user, int $slot, bool $ticked): bool
    {
        $minion = CustomMinion::query()
            ->where('user_id', $user->id)
            ->where('slot', $slot)
            ->first();

        if ($minion === null) {
            throw new MinionLookUnknownException;
        }

        if ($minion->enabled === $ticked) {
            return false;
        }

        $minion->enabled = $ticked;
        $minion->save();

        return true;
    }
}
