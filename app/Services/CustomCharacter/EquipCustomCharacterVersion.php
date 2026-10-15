<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterVersionException;
use App\Models\CustomCharacter;
use App\Models\User;

/**
 * Switches a player's fighter to one of their earlier confirmed versions, and
 * equips it. Only versions of the player's own fighter can be chosen.
 */
class EquipCustomCharacterVersion
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
     * Makes the version the fighter in use.
     *
     * @param  User  $user  the owner
     * @param  string  $hash  the version hash to switch to
     * @return CustomCharacter the fighter, now on that version and equipped
     *
     * @throws CustomCharacterVersionException when the player has no fighter or no such version
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user, string $hash): CustomCharacter
    {
        $character = $this->lock->within($user->id, function () use ($user, $hash): CustomCharacter {
            $character = $user->customCharacter()->first() ?? throw new CustomCharacterVersionException;
            $version = $character->versions()->where('version_hash', $hash)->first() ?? throw new CustomCharacterVersionException;

            $character->update(['version_hash' => $version->version_hash, 'animations' => $version->animations, 'active' => true]);

            return $character;
        });

        $this->announce->execute($user);

        return $character;
    }
}
