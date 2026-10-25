<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterStorageException;
use App\Models\CustomCharacterDraft;
use App\Models\CustomCharacterVersion;
use App\Models\CustomMinion;
use App\Models\CustomMinionDraft;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Removes old strip folders left behind when cleanup failed, without touching live versions.
 * Fighters (`custom-characters/`) and minions (`custom-minions/`) are swept as two separate
 * roots with their own references, so one kind's folder is never taken for the other's orphan:
 * a hash a minion copied from the fighter holds in both roots is judged once in each. A
 * player's references are read after their lock is taken, in both roots, so a confirm that
 * lands during the pass is never swept.
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
     * Deletes old orphan folders of both kinds after checking each player's current references under their lock.
     *
     * @return int number of folders deleted
     */
    public function execute(): int
    {
        return $this->sweepRoot(CustomCharacterStore::ROOT, fn (int $userId): array => $this->fighterReferences($userId))
            + $this->sweepRoot(CustomMinionStore::ROOT, fn (int $userId): array => $this->minionReferences($userId));
    }

    /**
     * Sweeps every player's folder under one root.
     *
     * @param  string  $root  `custom-characters` or `custom-minions`
     * @param  Closure(int): array{0: list<string>, 1: ?string}  $references  a player's referenced hashes and their draft's hash
     * @return int number of folders deleted
     */
    private function sweepRoot(string $root, Closure $references): int
    {
        $disk = $this->store->disk();
        $folders = rescue(fn (): array => $disk->directories($root), [], report: true);
        $deleted = 0;

        foreach ($folders as $userFolder) {
            $name = basename($userFolder);

            if (! ctype_digit($name)) {
                continue;
            }

            try {
                $deleted += $this->lock->within((int) $name, fn (): int => $this->sweepUser($disk, $userFolder, $references((int) $name)));
            } catch (CustomCharacterBusyException) {
                // A later daily pass handles this player after the active change finishes.
            }
        }

        return $deleted;
    }

    /**
     * A player's fighter hashes in use (the live one and its history) and their draft's hash.
     *
     * @param  int  $userId  player being swept
     * @return array{0: list<string>, 1: ?string}
     */
    private function fighterReferences(int $userId): array
    {
        $referenced = CustomCharacterVersion::query()->where('user_id', $userId)->pluck('version_hash')->all();
        $draft = CustomCharacterDraft::query()->where('user_id', $userId)->value('version_hash');

        return [$referenced, $draft];
    }

    /**
     * A player's minion hashes in use and their minion draft's hash.
     *
     * @param  int  $userId  player being swept
     * @return array{0: list<string>, 1: ?string}
     */
    private function minionReferences(int $userId): array
    {
        $referenced = CustomMinion::query()->where('user_id', $userId)->pluck('version_hash')->all();
        $draft = CustomMinionDraft::query()->where('user_id', $userId)->value('version_hash');

        return [$referenced, $draft];
    }

    /**
     * Removes only old unreferenced folders of one player under one root. The references
     * are read inside the player's lock, by the caller.
     *
     * @param  Filesystem  $disk  selected strip disk
     * @param  string  $userFolder  their folder on the disk
     * @param  array{0: list<string>, 1: ?string}  $references  hashes in use, and the draft's hash
     * @return int number of folders deleted
     */
    private function sweepUser(Filesystem $disk, string $userFolder, array $references): int
    {
        [$referenced, $draft] = $references;
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
                throw new CustomCharacterStorageException('An orphan strip folder could not be deleted.');
            }

            return 1;
        }, 0, report: true);
    }
}
