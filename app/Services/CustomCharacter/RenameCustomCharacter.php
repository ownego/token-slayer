<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterVersionException;
use App\Models\CustomCharacter;
use App\Models\User;

/**
 * Gives a player's fighter the name they chose: stripped of control, invisible
 * and direction-changing characters (other players read it on their screens),
 * trimmed, cut to the longest allowed, and the default when left empty. It runs
 * under the player's lock, so a rename racing a confirm keeps the later one.
 */
class RenameCustomCharacter
{
    /**
     * Create the action.
     *
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @return void
     */
    public function __construct(private readonly CustomCharacterLock $lock) {}

    /**
     * Renames the player's fighter.
     *
     * @param  User  $user  the owner
     * @param  string  $name  the name asked for
     * @return CustomCharacter the renamed fighter
     *
     * @throws CustomCharacterVersionException when the player has no fighter
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user, string $name): CustomCharacter
    {
        return $this->lock->within($user->id, function () use ($user, $name): CustomCharacter {
            $character = $user->customCharacter()->first() ?? throw new CustomCharacterVersionException;
            $clean = trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '', $name));
            $clean = mb_substr($clean, 0, (int) config('token_slayer.custom_character.name_max'));

            $character->update(['name' => $clean === '' ? (string) config('token_slayer.custom_character.default_name') : $clean]);

            return $character;
        });
    }
}
