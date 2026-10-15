<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Models\User;

/**
 * Throws away a player's staged draft and its files. The live fighter stays. It
 * runs under the player's lock, so it cannot cut a confirm in half.
 */
class DiscardCustomCharacterDraft
{
    /**
     * Create the action.
     *
     * @param  CustomCharacterStore  $store  owns the strips' files
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @return void
     */
    public function __construct(
        private readonly CustomCharacterStore $store,
        private readonly CustomCharacterLock $lock,
    ) {}

    /**
     * Removes the player's draft, if they have one.
     *
     * @param  User  $user  the owner
     * @return void
     *
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user): void
    {
        $this->lock->within($user->id, function () use ($user): void {
            $user->customCharacterDraft()->delete();
            $this->store->discardStaged($user->id);
        });
    }
}
