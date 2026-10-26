<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Models\CustomCharacter;
use App\Models\CustomCharacterVersion;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a custom fighter, every version of it, a draft waiting to be used and
 * all their files, and tells the battlefield, which drops the fighter live. The
 * player falls back to the roster character they had equipped (that choice is
 * never touched). The caller decides who may do this (the owner, or an admin: see
 * CustomCharacterPolicy). It runs under the player's lock, so it cannot race a
 * build or a confirm, and the files go only after the database change is in.
 */
class RemoveCustomCharacter
{
    /**
     * Create the action.
     *
     * @param  CustomCharacterStore  $store  owns the strips' files
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @param  AnnounceCustomCharacter  $announce  tells the battlefield
     * @return void
     */
    public function __construct(
        private readonly CustomCharacterStore $store,
        private readonly CustomCharacterLock $lock,
        private readonly AnnounceCustomCharacter $announce,
    ) {}

    /**
     * Removes the fighter.
     *
     * @param  CustomCharacter  $character  the fighter to remove
     * @return void
     *
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(CustomCharacter $character): void
    {
        $owner = $character->user;

        $this->lock->within($owner->id, function () use ($owner): void {
            DB::transaction(function () use ($owner): void {
                $owner->customCharacterDraft()->delete();
                CustomCharacterVersion::query()->where('user_id', $owner->id)->delete();
            });

            $this->store->forget($owner->id);
        });

        $this->announce->execute($owner);
    }
}
