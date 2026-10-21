<?php

namespace App\Services\CustomCharacter;

use App\Enums\CustomMinionSource;
use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterStorageException;
use App\Exceptions\CustomCharacterVersionException;
use App\Exceptions\CustomMinionDuplicateException;
use App\Exceptions\CustomMinionSetFullException;
use App\Models\CustomMinion;
use App\Models\User;

/**
 * Adds a copy of the player's custom fighter to their minion set. It is a copy:
 * the strips are copied into the minions' own folder, so later changing or
 * removing the fighter never touches the minion, and the fighter's files are never
 * touched here.
 */
class AddFighterAsMinion
{
    /**
     * Create the action.
     *
     * @param  CustomMinionStore  $store  owns the minions' files
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @param  MeasureStripBodyHeight  $measure  reads how tall the copied strip's character is
     * @param  AnnounceCustomMinions  $announce  tells the battlefield
     * @return void
     */
    public function __construct(
        private readonly CustomMinionStore $store,
        private readonly CustomCharacterLock $lock,
        private readonly MeasureStripBodyHeight $measure,
        private readonly AnnounceCustomMinions $announce,
    ) {}

    /**
     * Adds the player's current custom fighter to their set.
     *
     * @param  User  $user  the owner
     * @return CustomMinion the player's minion, now in the set
     *
     * @throws CustomCharacterVersionException when the player has no custom fighter
     * @throws CustomMinionDuplicateException when that fighter is already in the set
     * @throws CustomMinionSetFullException when the player already holds the most minions they can
     * @throws CustomCharacterStorageException when the fighter's files cannot be copied
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user): CustomMinion
    {
        $minion = $this->lock->within($user->id, fn (): CustomMinion => $this->add($user));

        $this->announce->execute($user);

        return $minion;
    }

    /**
     * Does the add while the player's lock is held.
     *
     * @param  User  $user  the owner
     * @return CustomMinion
     */
    private function add(User $user): CustomMinion
    {
        $fighter = $user->customCharacter()->first();

        if ($fighter === null) {
            throw new CustomCharacterVersionException;
        }

        if ($user->customMinions()->where('version_hash', $fighter->version_hash)->exists()) {
            throw new CustomMinionDuplicateException;
        }

        if ($user->customMinions()->count() >= (int) config('token_slayer.custom_character.minions_max')) {
            throw new CustomMinionSetFullException;
        }

        $this->store->adopt($user->id, $fighter->version_hash, CustomCharacterStore::folder($user->id, $fighter->version_hash));

        return $user->customMinions()->create([
            'version_hash' => $fighter->version_hash,
            'animations' => $fighter->animations,
            'body_px' => $this->measureAdopted($user, $fighter->version_hash),
            'source' => CustomMinionSource::Fighter,
        ]);
    }

    /**
     * Measures the idle strip that was just copied into the minions' folder.
     *
     * @param  User  $user  the owner
     * @param  string  $hash  the version hash of the copied minion
     * @return int
     *
     * @throws CustomCharacterStorageException when the copied strip cannot be read
     */
    private function measureAdopted(User $user, string $hash): int
    {
        $bytes = rescue(fn () => $this->store->disk()->get(CustomMinionStore::folder($user->id, $hash).'/idle.png'), report: true);
        $strip = is_string($bytes) ? @imagecreatefromstring($bytes) : false;

        if ($strip === false) {
            throw new CustomCharacterStorageException('The files to copy are gone. Please try again.');
        }

        return $this->measure->execute($strip);
    }
}
