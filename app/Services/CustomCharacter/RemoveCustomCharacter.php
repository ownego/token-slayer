<?php

namespace App\Services\CustomCharacter;

use App\Models\User;

/**
 * Deletes a user's custom character and its files.
 */
class RemoveCustomCharacter
{
    /**
     * Create the action.
     *
     * @param  CustomCharacterStore  $store  owns the character's files
     * @return void
     */
    public function __construct(private readonly CustomCharacterStore $store) {}

    /**
     * Removes the user's character, if they have one.
     *
     * @param  User  $user  the owner
     * @return void
     */
    public function execute(User $user): void
    {
        $user->customCharacter()->delete();
        $this->store->forget($user->id);
    }
}
