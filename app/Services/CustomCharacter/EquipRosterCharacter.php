<?php

namespace App\Services\CustomCharacter;

use App\Enums\FighterCharacter;
use App\Exceptions\CustomCharacterBusyException;
use App\Models\User;

/**
 * Equips a roster character for the player, which takes their custom fighter
 * out of use (it is kept, for equipping again), and tells the battlefield.
 */
class EquipRosterCharacter
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
     * Makes the roster character the one in use.
     *
     * @param  User  $user  the owner
     * @param  FighterCharacter  $character  the roster character to equip
     * @return void
     *
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user, FighterCharacter $character): void
    {
        $this->lock->within($user->id, function () use ($user, $character): void {
            $user->forceFill(['equipped_character' => $character])->save();
            $user->customCharacter()->update(['active' => false]);
        });

        $this->announce->execute($user);
    }
}
