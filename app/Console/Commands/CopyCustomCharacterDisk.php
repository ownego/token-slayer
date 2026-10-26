<?php

namespace App\Console\Commands;

use App\Exceptions\CustomCharacterStorageException;
use App\Services\CustomCharacter\CopyCustomCharacterDisk as CopyFiles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('custom-characters:copy-disk {from : The disk the files are on now} {to : The disk they should be on}')]
#[Description('Copy every custom fighter and minion file to another disk, before pointing custom_character.disk at it')]
class CopyCustomCharacterDisk extends Command
{
    /**
     * Copies the files and reports how many.
     *
     * @param  CopyFiles  $copy  copies the files
     * @return int the command exit code
     */
    public function handle(CopyFiles $copy): int
    {
        $from = (string) $this->argument('from');
        $to = (string) $this->argument('to');

        try {
            $copied = $copy->execute($from, $to);
        } catch (CustomCharacterStorageException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Copied '.$copied.' '.($copied === 1 ? 'file' : 'files')." from {$from} to {$to}");

        return self::SUCCESS;
    }
}
