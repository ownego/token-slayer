<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterVersionException;
use App\Models\CustomCharacter;
use App\Models\User;

/**
 * Gives a player's fighter the name they chose: trimmed, cut to the longest
 * allowed, and the default when left empty.
 */
class RenameCustomCharacter
{
    /**
     * Renames the player's fighter.
     *
     * @param  User  $user  the owner
     * @param  string  $name  the name asked for
     * @return CustomCharacter the renamed fighter
     *
     * @throws CustomCharacterVersionException when the player has no fighter
     */
    public function execute(User $user, string $name): CustomCharacter
    {
        $character = $user->customCharacter()->first() ?? throw new CustomCharacterVersionException;
        $name = mb_substr(trim($name), 0, (int) config('token_slayer.custom_character.name_max'));

        $character->update(['name' => $name === '' ? (string) config('token_slayer.custom_character.default_name') : $name]);

        return $character;
    }
}
