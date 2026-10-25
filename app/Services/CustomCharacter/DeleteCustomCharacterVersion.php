<?php

namespace App\Services\CustomCharacter;

use App\Enums\CustomSpriteState;
use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterVersionException;
use App\Models\User;

/**
 * Deletes one of a player's earlier confirmed versions: the row first, the
 * files only once it is gone. The version in use cannot be deleted (remove the
 * whole fighter for that), so the battlefield never needs telling.
 */
class DeleteCustomCharacterVersion
{
    /**
     * Create the action.
     *
     * @param  CustomCharacterStore  $store  owns the strips' files
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @return void
     */
    public function __construct(
        private readonly CustomCharacterStore $store,
        private readonly CustomCharacterLock $lock,
    ) {}

    /**
     * Deletes the version.
     *
     * @param  User  $user  the owner
     * @param  string  $hash  the version hash to delete
     * @return void
     *
     * @throws CustomCharacterVersionException when the version is missing, not the player's, or in use
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user, string $hash): void
    {
        $this->lock->within($user->id, function () use ($user, $hash): void {
            $character = $user->customCharacter()->first() ?? throw new CustomCharacterVersionException;

            if ($character->version_hash === $hash) {
                throw new CustomCharacterVersionException('That version is the one in use. Equip another first, or remove the fighter.');
            }

            ($character->versions()->where('state', CustomSpriteState::Previous->value)->where('version_hash', $hash)->first() ?? throw new CustomCharacterVersionException)->delete();

            // the files go while the lock is still held: a re-confirm of the same sheet cannot slip in between
            $this->store->forgetVersion($user->id, $hash);
        });
    }
}
