<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterStorageException;
use App\Exceptions\CustomMinionDuplicateException;
use App\Exceptions\CustomMinionSetFullException;
use App\Exceptions\InvalidPoseSheetException;
use App\Models\CustomMinionDraft;
use App\Models\User;

/**
 * Builds an uploaded pose sheet into strips and stages them as the player's
 * minion draft, to be previewed before it joins their set. The set is never
 * touched here. A new build replaces the earlier draft, but only once the new
 * files are safely written, so a refused sheet or a failed write keeps what was
 * there. A player with a full set, or a sheet that builds the same minion they
 * already have, is refused before anything is kept.
 */
class StageCustomMinion
{
    /**
     * Create the action.
     *
     * @param  CustomCharacterBuilder  $builder  turns the sheet into strips
     * @param  CustomMinionStore  $store  owns the minions' files
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @param  MeasureStripBodyHeight  $measure  reads how tall the built minion is
     * @return void
     */
    public function __construct(
        private readonly CustomCharacterBuilder $builder,
        private readonly CustomMinionStore $store,
        private readonly CustomCharacterLock $lock,
        private readonly MeasureStripBodyHeight $measure,
    ) {}

    /**
     * Stages the player's minion draft from the uploaded sheet.
     *
     * @param  User  $user  the owner
     * @param  string  $bytes  the uploaded pose sheet's bytes
     * @return CustomMinionDraft the staged draft
     *
     * @throws CustomMinionSetFullException when the player already holds the most minions they can
     * @throws CustomMinionDuplicateException when the sheet builds a minion the player already has
     * @throws InvalidPoseSheetException when the sheet does not meet the pose sheet contract
     * @throws CustomCharacterStorageException when the strips cannot be written
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user, string $bytes): CustomMinionDraft
    {
        return $this->lock->within($user->id, fn (): CustomMinionDraft => $this->stage($user, $bytes));
    }

    /**
     * Does the staging while the player's lock is held.
     *
     * @param  User  $user  the owner
     * @param  string  $bytes  the uploaded pose sheet's bytes
     * @return CustomMinionDraft
     */
    private function stage(User $user, string $bytes): CustomMinionDraft
    {
        if ($user->customMinions()->count() >= (int) config('token_slayer.custom_character.minions_max')) {
            throw new CustomMinionSetFullException;
        }

        $strips = $this->builder->execute($bytes);
        $bodyPx = $this->measure->execute($strips['idle']['image']);
        $staged = $this->store->stage($user->id, $strips);

        if ($user->customMinions()->where('version_hash', $staged['hash'])->exists()) {
            $this->dropRefusedBuild($user);

            throw new CustomMinionDuplicateException;
        }

        $draft = CustomMinionDraft::updateOrCreate(
            ['user_id' => $user->id],
            ['version_hash' => $staged['hash'], 'animations' => $staged['animations'], 'body_px' => $bodyPx],
        );

        if (! $draft->wasRecentlyCreated && ! $draft->wasChanged()) {
            // the same sheet built again changes nothing in the row; a fresh timestamp keeps the prune off it
            $draft->touch();
        }

        $this->store->pruneStaged($user->id, $staged['hash']);

        return $draft;
    }

    /**
     * Removes the files of a build that was refused, keeping the earlier draft's
     * files when the player has one (its row still points at them).
     *
     * @param  User  $user  the owner
     * @return void
     */
    private function dropRefusedBuild(User $user): void
    {
        $draft = $user->customMinionDraft()->first();

        if ($draft === null) {
            $this->store->discardStaged($user->id);

            return;
        }

        $this->store->pruneStaged($user->id, $draft->version_hash);
    }
}
