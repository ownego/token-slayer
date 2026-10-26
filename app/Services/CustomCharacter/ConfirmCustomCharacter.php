<?php

namespace App\Services\CustomCharacter;

use App\Enums\CustomSpriteState;
use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterDraftMissingException;
use App\Exceptions\CustomCharacterStorageException;
use App\Models\CustomCharacter;
use App\Models\CustomCharacterVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Makes a player's staged draft their equipped fighter. It only ever acts on the
 * draft of the player who asks (never one staged by someone else), and asking
 * twice for the version already in use is harmless. The old version stays in the
 * history for the player to switch back to, up to the configured number; files
 * of versions that drop out are deleted only after the database change is in.
 */
class ConfirmCustomCharacter
{
    /**
     * Create the action.
     *
     * @param  CustomCharacterStore  $store  owns the strips' files
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @param  AnnounceCustomCharacter  $announce  tells the battlefield
     * @return void
     */
    public function __construct(
        private readonly CustomCharacterStore $store,
        private readonly CustomCharacterLock $lock,
        private readonly AnnounceCustomCharacter $announce,
    ) {}

    /**
     * Confirms the draft with the given version hash as the player's fighter.
     *
     * @param  User  $user  the owner
     * @param  string  $hash  the version hash of the draft the player previewed
     * @return CustomCharacter the player's fighter, now equipped and on that version
     *
     * @throws CustomCharacterDraftMissingException when the player has no such draft
     * @throws CustomCharacterStorageException when the staged files cannot be moved into place
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user, string $hash): CustomCharacter
    {
        $character = $this->lock->within($user->id, fn (): CustomCharacter => $this->confirm($user, $hash));

        $this->announce->execute($user);

        return $character;
    }

    /**
     * Does the confirm while the player's lock is held.
     *
     * @param  User  $user  the owner
     * @param  string  $hash  the version hash of the draft
     * @return CustomCharacter
     */
    private function confirm(User $user, string $hash): CustomCharacter
    {
        $draft = $user->customCharacterDraft()->first();

        if ($draft === null || $draft->version_hash !== $hash) {
            $current = $user->customCharacter()->first();

            if ($current !== null && $current->version_hash === $hash) {
                return $current;
            }

            throw new CustomCharacterDraftMissingException;
        }

        $this->store->promote($user->id, $hash);

        $character = DB::transaction(function () use ($user, $draft, $hash): CustomCharacter {
            $current = $user->customCharacter()->first();

            if ($current?->version_hash === $hash) {
                $current->fill(['animations' => $draft->animations, 'active' => true])->save();
                $character = $current;
            } else {
                $name = $current?->name ?? (string) config('token_slayer.custom_character.default_name');
                $current?->demote();
                $character = $this->makeCurrent($user, $hash, $draft->animations, $name);
            }

            $this->trimHistory($user);
            $draft->delete();

            return $character;
        });

        $this->store->prune($user->id, $character->versions()->pluck('version_hash')->all());

        return $character;
    }

    /**
     * Makes the version with this hash the fighter in use, equipped: the earlier version
     * when the hash is already in the history, else a new row.
     *
     * @param  User  $user  the owner
     * @param  string  $hash  the version hash
     * @param  array<string, array{frames: int, rate: int}>  $animations  the version's animation table
     * @param  string  $name  the fighter's name, carried over from the version it replaces
     * @return CustomCharacter the fighter
     */
    private function makeCurrent(User $user, string $hash, array $animations, string $name): CustomCharacter
    {
        $earlier = CustomCharacterVersion::query()
            ->where('user_id', $user->id)
            ->where('state', CustomSpriteState::Previous->value)
            ->where('version_hash', $hash)
            ->first();

        if ($earlier === null) {
            return CustomCharacter::create(['user_id' => $user->id, 'name' => $name, 'version_hash' => $hash, 'animations' => $animations, 'active' => true]);
        }

        $earlier->promote($name, $animations);

        return CustomCharacter::query()->findOrFail($earlier->getKey());
    }

    /**
     * Drops the oldest earlier versions beyond the configured history, which counts the
     * version in use too and never drops it.
     *
     * @param  User  $user  the owner
     * @return void
     */
    private function trimHistory(User $user): void
    {
        $limit = max(1, (int) config('token_slayer.custom_character.max_versions'));
        $others = CustomCharacterVersion::query()
            ->where('user_id', $user->id)
            ->where('state', CustomSpriteState::Previous->value)
            ->orderBy('id')
            ->get();

        foreach ($others->take(max(0, $others->count() - ($limit - 1))) as $old) {
            $old->delete();
        }
    }
}
