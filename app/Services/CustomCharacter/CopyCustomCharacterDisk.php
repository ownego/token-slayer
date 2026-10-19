<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterStorageException;
use Illuminate\Support\Facades\Storage;

/**
 * Copies every custom fighter and custom minion file from one disk to another. Changing
 * `custom_character.disk` after fighters exist points the app at an empty place:
 * run this first, then change the setting, and nothing is orphaned. Both roots (fighters
 * and minions) go across. The source is
 * left as it is.
 */
class CopyCustomCharacterDisk
{
    /**
     * Copies the files, streaming each so a large strip is never held in memory twice.
     *
     * @param  string  $from  the disk the files are on now
     * @param  string  $to  the disk they should be on
     * @return int how many files were copied
     *
     * @throws CustomCharacterStorageException when a file cannot be read or written
     */
    public function execute(string $from, string $to): int
    {
        $source = Storage::disk($from);
        $target = Storage::disk($to);
        $copied = 0;

        foreach ([CustomCharacterStore::ROOT, CustomMinionStore::ROOT] as $root) {
            foreach ($source->allFiles($root) as $path) {
                $stream = $source->readStream($path);

                if ($stream === null || $target->writeStream($path, $stream) === false) {
                    throw new CustomCharacterStorageException("Could not copy {$path} from {$from} to {$to}.");
                }

                if (is_resource($stream)) {
                    fclose($stream);
                }

                $copied++;
            }
        }

        return $copied;
    }
}
