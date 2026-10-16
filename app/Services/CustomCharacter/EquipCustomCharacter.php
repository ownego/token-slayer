<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Models\CustomCharacter;
use App\Models\User;

/**
 * Equips the player's custom fighter, if they have one, and tells the
 * battlefield. The roster character they had equipped is left as it is, so
 * picking it again later puts them back.
 */
class EquipCustomCharacter
{
    /**
     * Create the action.
     *
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @param  AnnounceCustomCharacter  $announce  tells the battlefield
     * @return void
     */
    public function __construct(
        private readonly CustomCharacterLock $lock,
        private readonly AnnounceCustomCharacter $announce,
    ) {}

    /**
     * Makes the custom fighter the one in use.
     *
     * @param  User  $user  the owner
     * @return CustomCharacter|null the fighter, now equipped; null when the player has none
     *
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user): ?CustomCharacter
    {
        $character = $this->lock->within($user->id, function () use ($user): ?CustomCharacter {
            $character = $user->customCharacter()->first();
            $character?->update(['active' => true]);

            return $character;
        });

        if ($character !== null) {
            $this->announce->execute($user);
        }

        return $character;
    }
}
