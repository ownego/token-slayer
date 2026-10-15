<?php

namespace App\Services\CustomCharacter;

use App\Models\User;

/**
 * Throws away a player's staged draft and its files. The live fighter stays.
 */
class DiscardCustomCharacterDraft
{
    /**
     * Create the action.
     *
     * @param  CustomCharacterStore  $store  owns the strips' files
     * @return void
     */
    public function __construct(private readonly CustomCharacterStore $store) {}

    /**
     * Removes the player's draft, if they have one.
     *
     * @param  User  $user  the owner
     * @return void
     */
    public function execute(User $user): void
    {
        $user->customCharacterDraft()->delete();
        $this->store->discardStaged($user->id);
    }
}
