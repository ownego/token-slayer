<?php

namespace App\Services\CustomCharacter;

use App\Models\CustomCharacter;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a custom fighter, every version of it and its files, and tells the
 * battlefield, which drops the fighter live. The player falls back to the roster
 * character they had equipped (that choice is never touched). The caller decides
 * who may do this (the owner, or an admin: see CustomCharacterPolicy); the files
 * go only after the database change is in.
 */
class RemoveCustomCharacter
{
    /**
     * Create the action.
     *
     * @param  CustomCharacterStore  $store  owns the strips' files
     * @param  AnnounceCustomCharacter  $announce  tells the battlefield
     * @return void
     */
    public function __construct(
        private readonly CustomCharacterStore $store,
        private readonly AnnounceCustomCharacter $announce,
    ) {}

    /**
     * Removes the fighter.
     *
     * @param  CustomCharacter  $character  the fighter to remove
     * @return void
     */
    public function execute(CustomCharacter $character): void
    {
        $owner = $character->user;

        DB::transaction(fn () => $character->delete());

        $this->store->forget($character->user_id);
        $this->announce->execute($owner);
    }
}
