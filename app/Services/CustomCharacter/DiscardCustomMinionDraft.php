<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomMinionDraftMissingException;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Throws away a player's minion draft and its files. The set stays. It runs under
 * the player's lock, so it cannot cut an add in half. It is bound to the version the
 * caller saw (and, for the prune, to how old it was), re-checked once the lock is held,
 * so a draft the player built in between is never thrown away by a stale tab or pass.
 */
class DiscardCustomMinionDraft
{
    /**
     * Create the action.
     *
     * @param  CustomMinionStore  $store  owns the minions' files
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @return void
     */
    public function __construct(
        private readonly CustomMinionStore $store,
        private readonly CustomCharacterLock $lock,
    ) {}

    /**
     * Removes the player's minion draft, if it is still the one the caller saw.
     *
     * The whole staged area goes with it, which is safe: under this lock the staged area
     * only ever holds the draft's own version (StageCustomMinion prunes the rest).
     *
     * @param  User  $user  the owner
     * @param  string  $hash  the version hash of the draft the caller saw
     * @param  CarbonInterface|null  $staleBefore  when given, the draft must also not have been touched since then
     * @return void
     *
     * @throws CustomMinionDraftMissingException when the player has no draft, or not that version, or it was touched since $staleBefore
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user, string $hash, ?CarbonInterface $staleBefore = null): void
    {
        $this->lock->within($user->id, function () use ($user, $hash, $staleBefore): void {
            $draft = $user->customMinionDraft()->first();

            if ($draft === null || $draft->version_hash !== $hash || ($staleBefore !== null && ! $draft->updated_at->lt($staleBefore))) {
                throw new CustomMinionDraftMissingException;
            }

            $draft->delete();
            $this->store->discardStaged($user->id);
        });
    }
}
