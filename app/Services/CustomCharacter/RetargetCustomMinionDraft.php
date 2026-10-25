<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomMinionDraftMissingException;
use App\Exceptions\CustomMinionMissingException;
use App\Models\CustomMinionDraft;
use App\Models\User;

/**
 * Aims a player's built minion draft at the minion in a slot, so confirming it
 * replaces that minion instead of adding a new one (the player built first and
 * chose Replace after). The draft's files and look do not change, and neither does
 * the player's set, so the pool's revision stays. It runs under the player's lock
 * and is bound to the version the caller saw, so a draft the player built in
 * between is never aimed by a stale tab.
 */
class RetargetCustomMinionDraft
{
    /**
     * Create the action.
     *
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @return void
     */
    public function __construct(private readonly CustomCharacterLock $lock) {}

    /**
     * Makes the draft with the given version hash replace the minion in a slot.
     *
     * @param  User  $user  the owner
     * @param  string  $hash  the version hash of the draft the caller saw
     * @param  int  $slot  the slot of the minion to replace
     * @return CustomMinionDraft the draft, now aimed at the slot
     *
     * @throws CustomMinionDraftMissingException when the player has no draft, or not that version
     * @throws CustomMinionMissingException when $slot is not a minion of this player's
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user, string $hash, int $slot): CustomMinionDraft
    {
        return $this->lock->within($user->id, function () use ($user, $hash, $slot): CustomMinionDraft {
            $draft = $user->customMinionDraft()->first();

            if ($draft === null || $draft->version_hash !== $hash) {
                throw new CustomMinionDraftMissingException;
            }

            if (! $user->customMinions()->where('slot', $slot)->exists()) {
                throw new CustomMinionMissingException;
            }

            $draft->slot = $slot;
            $draft->save();

            if (! $draft->wasChanged()) {
                // already aimed there; a fresh timestamp still keeps the prune off the draft
                $draft->touch();
            }

            return $draft;
        });
    }
}
