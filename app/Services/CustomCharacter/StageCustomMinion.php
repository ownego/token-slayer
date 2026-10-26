<?php

namespace App\Services\CustomCharacter;

use App\Enums\CustomMinionSource;
use App\Exceptions\BlankStripException;
use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterStorageException;
use App\Exceptions\CustomMinionDuplicateException;
use App\Exceptions\CustomMinionMissingException;
use App\Exceptions\CustomMinionSetFullException;
use App\Exceptions\InvalidPoseSheetException;
use App\Models\CustomMinionDraft;
use App\Models\User;
use Throwable;

/**
 * Builds an uploaded pose sheet into strips and stages them as the player's
 * minion draft, to be previewed before it joins their set. The set is never
 * touched here. With a slot the draft will replace the minion in that slot when
 * the player confirms it; without one it adds a new minion. A new build replaces
 * the earlier draft, but only once the new files are safely written, so a refused
 * sheet or a failed write or insert keeps what was there and leaves no new folder
 * behind. A player with a full set (when adding), or a sheet that builds the same
 * minion they already have, is refused before anything is kept.
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
     * @param  CustomMinionSlots  $slots  knows whether the set has room
     * @return void
     */
    public function __construct(
        private readonly CustomCharacterBuilder $builder,
        private readonly CustomMinionStore $store,
        private readonly CustomCharacterLock $lock,
        private readonly MeasureStripBodyHeight $measure,
        private readonly CustomMinionSlots $slots,
    ) {}

    /**
     * Stages the player's minion draft from the uploaded sheet.
     *
     * @param  User  $user  the owner
     * @param  string  $bytes  the uploaded pose sheet's bytes
     * @param  int|null  $slot  the slot of the minion the draft will replace, or null to add a new one
     * @return CustomMinionDraft the staged draft
     *
     * @throws CustomMinionMissingException when $slot is not a minion of this player's
     * @throws CustomMinionSetFullException when adding, and the player already holds the most minions they can
     * @throws CustomMinionDuplicateException when the sheet builds a minion the player already has
     * @throws InvalidPoseSheetException when the sheet does not meet the pose sheet contract
     * @throws BlankStripException when the built idle pose has no body
     * @throws CustomCharacterStorageException when the strips cannot be written
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user, string $bytes, ?int $slot = null): CustomMinionDraft
    {
        return $this->lock->within($user->id, fn (): CustomMinionDraft => $this->stage($user, $bytes, $slot));
    }

    /**
     * Writes the draft row for strips already in the staged area, and drops every
     * other staged version. The caller holds the player's lock, and calls
     * dropRefusedBuild() when this throws, so the draft the player had stays with its files.
     *
     * @param  User  $user  the owner
     * @param  string  $hash  the staged version hash
     * @param  array<string, array{frames: int, rate: int}>  $animations  the version's animation table
     * @param  int  $bodyPx  how tall the minion's body is
     * @param  CustomMinionSource  $source  how the minion was made
     * @param  string|null  $name  the name the minion will carry, null to take its slot's
     * @param  int|null  $slot  the slot the draft will replace, null when it adds a minion
     * @return CustomMinionDraft
     */
    public function saveDraft(User $user, string $hash, array $animations, int $bodyPx, CustomMinionSource $source, ?string $name, ?int $slot): CustomMinionDraft
    {
        $draft = CustomMinionDraft::updateOrCreate(
            ['user_id' => $user->id],
            ['version_hash' => $hash, 'slot' => $slot, 'name' => $name, 'animations' => $animations, 'body_px' => $bodyPx, 'source' => $source],
        );

        if (! $draft->wasRecentlyCreated && ! $draft->wasChanged()) {
            // the same sheet built again changes nothing in the row; a fresh timestamp keeps the prune off it
            $draft->touch();
        }

        $this->store->pruneStaged($user->id, $hash);

        return $draft;
    }

    /**
     * Removes the files of a build that was refused, keeping the earlier draft's
     * files when the player has one (its row still points at them). Never throws:
     * it runs while another failure is already on its way out.
     *
     * @param  User  $user  the owner
     * @return void
     */
    public function dropRefusedBuild(User $user): void
    {
        rescue(function () use ($user): void {
            $kept = $user->customMinionDraft()->value('version_hash');

            if ($kept === null) {
                $this->store->discardStaged($user->id);

                return;
            }

            $this->store->pruneStaged($user->id, $kept);
        }, report: true);
    }

    /**
     * Does the staging while the player's lock is held.
     *
     * @param  User  $user  the owner
     * @param  string  $bytes  the uploaded pose sheet's bytes
     * @param  int|null  $slot  the slot the draft will replace, or null
     * @return CustomMinionDraft
     */
    private function stage(User $user, string $bytes, ?int $slot): CustomMinionDraft
    {
        // The relation may have been read before the lock was taken; the set is read afresh under it.
        $user->unsetRelation('customMinions');

        if ($slot !== null) {
            if (! $user->customMinions()->where('slot', $slot)->exists()) {
                throw new CustomMinionMissingException;
            }
        } elseif ($this->slots->isFull($user)) {
            throw new CustomMinionSetFullException;
        }

        $strips = $this->builder->execute($bytes);
        $bodyPx = $this->measure->execute($strips['idle']['image']);
        $staged = $this->store->stage($user->id, $strips);

        try {
            if ($user->customMinions()->where('version_hash', $staged['hash'])->exists()) {
                throw new CustomMinionDuplicateException;
            }

            return $this->saveDraft($user, $staged['hash'], $staged['animations'], $bodyPx, CustomMinionSource::Upload, null, $slot);
        } catch (Throwable $exception) {
            $this->dropRefusedBuild($user);

            throw $exception;
        }
    }
}
