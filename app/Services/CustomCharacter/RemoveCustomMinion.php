<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Models\CustomMinion;
use Illuminate\Support\Facades\DB;

/**
 * Removes one minion from a player's set and deletes its files, and tells the
 * battlefield, which swaps the player's minions live. The pool's revision goes up in
 * the same transaction as the delete, so a client can tell this pool from the one
 * before. The caller decides who may do this (the owner, or an admin holding
 * `Delete:CustomMinion`: see CustomMinionPolicy). It runs under the owner's lock, so
 * it cannot race an add, and the files go only after the database change is in.
 */
class RemoveCustomMinion
{
    /**
     * Create the action.
     *
     * @param  CustomMinionStore  $store  owns the minions' files
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @param  AnnounceCustomMinions  $announce  tells the battlefield
     * @return void
     */
    public function __construct(
        private readonly CustomMinionStore $store,
        private readonly CustomCharacterLock $lock,
        private readonly AnnounceCustomMinions $announce,
    ) {}

    /**
     * Removes the minion. A minion that is already gone (another tab, or an admin,
     * got there first) is left alone: no revision, no announcement.
     *
     * @param  CustomMinion  $minion  the minion to remove
     * @return void
     *
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(CustomMinion $minion): void
    {
        $owner = $minion->user;

        $removed = $this->lock->within($owner->id, function () use ($minion, $owner): bool {
            $current = CustomMinion::query()->whereKey($minion->getKey())->first();

            if ($current === null) {
                return false;
            }

            DB::transaction(function () use ($current, $owner): void {
                $current->delete();
                $owner->increment('minion_pool_rev');
            });

            $this->store->forgetVersion($owner->id, $current->version_hash);

            return true;
        });

        if ($removed) {
            $this->announce->execute($owner);
        }
    }
}
