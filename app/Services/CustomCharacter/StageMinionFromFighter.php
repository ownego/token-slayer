<?php

namespace App\Services\CustomCharacter;

use App\Enums\CustomMinionSource;
use App\Exceptions\BlankStripException;
use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterStorageException;
use App\Exceptions\CustomMinionDuplicateException;
use App\Exceptions\CustomMinionMissingException;
use App\Exceptions\CustomMinionNoFighterException;
use App\Exceptions\CustomMinionSetFullException;
use App\Models\CustomCharacter;
use App\Models\CustomMinionDraft;
use App\Models\User;
use GdImage;
use Throwable;

/**
 * Stages a copy of the player's custom fighter as their minion draft, so they
 * preview it before it joins the set (never added at once). It is a copy: the
 * strips are copied into the minions' own staged folder, so later changing or
 * removing the fighter never touches the minion, and the fighter's files are never
 * touched here. The fighter's own idle strip is measured before anything is copied,
 * and a failed copy or insert leaves no folder behind.
 */
class StageMinionFromFighter
{
    /**
     * Create the action.
     *
     * @param  StageCustomMinion  $stage  writes the draft row the same way an upload does
     * @param  CustomMinionStore  $store  owns the minions' files
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @param  MeasureStripBodyHeight  $measure  reads how tall the fighter's character is
     * @param  CustomMinionSlots  $slots  knows whether the set has room
     * @return void
     */
    public function __construct(
        private readonly StageCustomMinion $stage,
        private readonly CustomMinionStore $store,
        private readonly CustomCharacterLock $lock,
        private readonly MeasureStripBodyHeight $measure,
        private readonly CustomMinionSlots $slots,
    ) {}

    /**
     * Stages the player's current custom fighter as their minion draft.
     *
     * @param  User  $user  the owner
     * @param  int|null  $slot  the slot of the minion the draft will replace, or null to add a new one
     * @return CustomMinionDraft the staged draft, carrying the fighter's name
     *
     * @throws CustomMinionNoFighterException when the player has no custom fighter
     * @throws CustomMinionMissingException when $slot is not a minion of this player's
     * @throws CustomMinionDuplicateException when that fighter is already in the set
     * @throws CustomMinionSetFullException when adding, and the player already holds the most minions they can
     * @throws BlankStripException when the fighter's idle pose has no body
     * @throws CustomCharacterStorageException when the fighter's files cannot be read or copied
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user, ?int $slot = null): CustomMinionDraft
    {
        return $this->lock->within($user->id, fn (): CustomMinionDraft => $this->stageFighter($user, $slot));
    }

    /**
     * Does the staging while the player's lock is held.
     *
     * @param  User  $user  the owner
     * @param  int|null  $slot  the slot the draft will replace, or null
     * @return CustomMinionDraft
     */
    private function stageFighter(User $user, ?int $slot): CustomMinionDraft
    {
        $fighter = $user->customCharacter()->first() ?? throw new CustomMinionNoFighterException;

        $this->guardTarget($user, $fighter, $slot);

        $source = CustomCharacterStore::folder($user->id, $fighter->version_hash);
        $bodyPx = $this->measure->execute($this->readIdle($source));

        try {
            $this->store->adoptStaged($user->id, $fighter->version_hash, $source);

            return $this->stage->saveDraft($user, $fighter->version_hash, $fighter->animations, $bodyPx, CustomMinionSource::Fighter, $fighter->name, $slot);
        } catch (Throwable $exception) {
            $this->stage->dropRefusedBuild($user);

            throw $exception;
        }
    }

    /**
     * Refuses a target the draft could never be confirmed into.
     *
     * @param  User  $user  the owner
     * @param  CustomCharacter  $fighter  the fighter being copied
     * @param  int|null  $slot  the slot the draft will replace, or null
     * @return void
     */
    private function guardTarget(User $user, CustomCharacter $fighter, ?int $slot): void
    {
        // The relation may have been read before the lock was taken; the set is read afresh under it.
        $user->unsetRelation('customMinions');

        if ($slot !== null && ! $user->customMinions()->where('slot', $slot)->exists()) {
            throw new CustomMinionMissingException;
        }

        if ($user->customMinions()->where('version_hash', $fighter->version_hash)->exists()) {
            throw new CustomMinionDuplicateException;
        }

        if ($slot === null && $this->slots->isFull($user)) {
            throw new CustomMinionSetFullException;
        }
    }

    /**
     * Reads the fighter's own idle strip, which is measured before anything is copied.
     *
     * @param  string  $folder  the folder, on the disk, that holds the fighter version's strips
     * @return GdImage
     *
     * @throws CustomCharacterStorageException when the strip cannot be read
     */
    private function readIdle(string $folder): GdImage
    {
        $bytes = rescue(fn () => $this->store->disk()->get("{$folder}/idle.png"), report: true);
        $strip = is_string($bytes) ? @imagecreatefromstring($bytes) : false;

        if ($strip === false) {
            throw new CustomCharacterStorageException('The files to copy are gone. Please try again.');
        }

        return $strip;
    }
}
