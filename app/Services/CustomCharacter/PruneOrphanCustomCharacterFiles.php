<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterStorageException;
use App\Models\CustomCharacter;
use App\Models\CustomCharacterDraft;
use App\Models\CustomCharacterVersion;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Removes old strip folders left behind when cleanup failed, without touching live versions.
 */
class PruneOrphanCustomCharacterFiles
{
    /**
     * Create the sweep action.
     *
     * @param  CustomCharacterStore  $store  owns the selected storage disk
     * @param  CustomCharacterLock  $lock  serializes changes for each player
     * @return void
     */
    public function __construct(
        private readonly CustomCharacterStore $store,
        private readonly CustomCharacterLock $lock,
    ) {}

    /**
     * Deletes old orphan folders after checking each player's current references under their lock.
     *
     * @return int number of folders deleted
     */
    public function execute(): int
    {
        $disk = $this->store->disk();
        $folders = rescue(fn (): array => $disk->directories(CustomCharacterStore::ROOT), [], report: true);
        $deleted = 0;

        foreach ($folders as $userFolder) {
            $name = basename($userFolder);

            if (! ctype_digit($name)) {
                continue;
            }

            try {
                $deleted += $this->lock->within((int) $name, fn (): int => $this->sweepUser($disk, (int) $name, $userFolder));
            } catch (CustomCharacterBusyException) {
                // A later daily pass handles this player after the active change finishes.
            }
        }

        return $deleted;
    }

    /**
     * Re-reads a player's references inside the lock and removes only old unreferenced folders.
     *
     * @param  Filesystem  $disk  selected strip disk
     * @param  int  $userId  player being swept
     * @param  string  $userFolder  their root folder on the disk
     * @return int number of folders deleted
     */
    private function sweepUser(Filesystem $disk, int $userId, string $userFolder): int
    {
        $referenced = CustomCharacterVersion::query()
            ->whereHas('customCharacter', fn ($query) => $query->where('user_id', $userId))
            ->pluck('version_hash')->all();
        $active = CustomCharacter::query()->where('user_id', $userId)->value('version_hash');
        if ($active !== null) {
            $referenced[] = $active;
        }
        $draft = CustomCharacterDraft::query()->where('user_id', $userId)->value('version_hash');
        $folders = rescue(fn (): array => $disk->directories($userFolder), [], report: true);
        $deleted = 0;

        foreach ($folders as $folder) {
            $hash = basename($folder);

            if ($hash === CustomCharacterStore::STAGED) {
                if ($draft === null && $this->stagedIsOld($disk, $folder)) {
                    $deleted += $this->delete($disk, $folder);
                }

                continue;
            }

            if (in_array($hash, $referenced, true) || ! $this->isOld($disk, "{$folder}/idle.png")) {
                continue;
            }

            $deleted += $this->delete($disk, $folder);
        }

        return $deleted;
    }

    /**
     * Keeps a staged area if any child may still be young or has unreadable metadata.
     *
     * @param  Filesystem  $disk  selected strip disk
     * @param  string  $folder  staged area
     * @return bool whether all children are old enough to delete
     */
    private function stagedIsOld(Filesystem $disk, string $folder): bool
    {
        $children = rescue(fn (): array => $disk->directories($folder), null, report: true);
        if ($children === null) {
            return false;
        }

        foreach ($children as $child) {
            if (! $this->isOld($disk, "{$child}/idle.png")) {
                return false;
            }
        }

        return true;
    }

    /**
     * Missing or unreadable metadata counts as young so uncertain files survive.
     *
     * @param  Filesystem  $disk  selected strip disk
     * @param  string  $path  idle strip to check
     * @return bool whether it is at least one hour old
     */
    private function isOld(Filesystem $disk, string $path): bool
    {
        $age = rescue(fn (): int => time() - $disk->lastModified($path), 0, report: true);

        return $age >= 3600;
    }

    /**
     * Deletes an orphan and reports a storage failure without stopping the sweep.
     *
     * @param  Filesystem  $disk  selected strip disk
     * @param  string  $folder  orphan folder
     * @return int one when deleted, zero on failure
     *
     * @throws CustomCharacterStorageException internally when deletion returns false; rescue reports it
     */
    private function delete(Filesystem $disk, string $folder): int
    {
        return rescue(function () use ($disk, $folder): int {
            if (! $disk->deleteDirectory($folder)) {
                throw new CustomCharacterStorageException('An orphan fighter folder could not be deleted.');
            }

            return 1;
        }, 0, report: true);
    }
}
