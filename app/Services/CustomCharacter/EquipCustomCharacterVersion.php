<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterVersionException;
use App\Models\CustomCharacter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Switches a player's fighter to one of their earlier confirmed versions, and
 * equips it. Only versions of the player's own fighter can be chosen.
 */
class EquipCustomCharacterVersion
{
    /**
     * Create the action.
     *
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @param  AnnounceCustomCharacter  $announce  tells the battlefield
     * @return void
     */
    public function __construct(
        private readonly CustomCharacterLock $lock,
        private readonly AnnounceCustomCharacter $announce,
    ) {}

    /**
     * Makes the version the fighter in use.
     *
     * @param  User  $user  the owner
     * @param  string  $hash  the version hash to switch to
     * @return CustomCharacter the fighter, now on that version and equipped
     *
     * @throws CustomCharacterVersionException when the player has no fighter or no such version
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user, string $hash): CustomCharacter
    {
        $character = $this->lock->within($user->id, function () use ($user, $hash): CustomCharacter {
            $current = $user->customCharacter()->first() ?? throw new CustomCharacterVersionException;
            $version = $current->versions()->where('version_hash', $hash)->first() ?? throw new CustomCharacterVersionException;

            if ($version->getKey() === $current->getKey()) {
                $current->update(['active' => true]);

                return $current;
            }

            return DB::transaction(function () use ($current, $version): CustomCharacter {
                $name = (string) $current->name;
                $current->demote();
                $version->promote($name, $version->animations);

                return CustomCharacter::query()->findOrFail($version->getKey());
            });
        });

        $this->announce->execute($user);

        return $character;
    }
}
