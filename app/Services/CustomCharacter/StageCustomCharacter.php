<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterStorageException;
use App\Exceptions\InvalidPoseSheetException;
use App\Models\CustomCharacterDraft;
use App\Models\User;

/**
 * Builds an uploaded pose sheet into strips and stages them as the player's
 * draft, to be previewed before it goes live. The live fighter is never touched
 * here. A new build replaces the earlier draft, but only once the new files are
 * safely written, so a refused sheet or a failed write keeps what was there.
 */
class StageCustomCharacter
{
    /**
     * Create the action.
     *
     * @param  CustomCharacterBuilder  $builder  turns the sheet into strips
     * @param  CustomCharacterStore  $store  owns the strips' files
     * @param  CustomCharacterLock  $lock  keeps one player's builds from interleaving
     * @return void
     */
    public function __construct(
        private readonly CustomCharacterBuilder $builder,
        private readonly CustomCharacterStore $store,
        private readonly CustomCharacterLock $lock,
    ) {}

    /**
     * Stages the player's draft from the uploaded sheet.
     *
     * @param  User  $user  the owner
     * @param  string  $bytes  the uploaded pose sheet's bytes
     * @return CustomCharacterDraft the staged draft
     *
     * @throws InvalidPoseSheetException when the sheet does not meet the pose sheet contract
     * @throws CustomCharacterStorageException when the strips cannot be written
     * @throws CustomCharacterBusyException when another build of this player's is still running
     */
    public function execute(User $user, string $bytes): CustomCharacterDraft
    {
        return $this->lock->within($user->id, function () use ($user, $bytes): CustomCharacterDraft {
            $staged = $this->store->stage($user->id, $this->builder->execute($bytes));

            $draft = CustomCharacterDraft::updateOrCreate(
                ['user_id' => $user->id],
                ['version_hash' => $staged['hash'], 'animations' => $staged['animations']],
            );

            $this->store->pruneStaged($user->id, $staged['hash']);

            return $draft;
        });
    }
}
