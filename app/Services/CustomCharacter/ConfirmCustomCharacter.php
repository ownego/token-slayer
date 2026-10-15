<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterDraftMissingException;
use App\Exceptions\CustomCharacterStorageException;
use App\Models\CustomCharacter;
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
            $character = CustomCharacter::firstOrNew(['user_id' => $user->id]);
            $character->name ??= (string) config('token_slayer.custom_character.default_name');
            $character->fill(['version_hash' => $hash, 'animations' => $draft->animations, 'active' => true])->save();
            $character->versions()->updateOrCreate(['version_hash' => $hash], ['animations' => $draft->animations]);
            $this->trimHistory($character);
            $draft->delete();

            return $character;
        });

        $this->store->prune($user->id, $character->versions()->pluck('version_hash')->all());

        return $character;
    }

    /**
     * Drops the oldest versions beyond the configured history, never the one in use.
     *
     * @param  CustomCharacter  $character  the fighter
     * @return void
     */
    private function trimHistory(CustomCharacter $character): void
    {
        $limit = max(1, (int) config('token_slayer.custom_character.max_versions'));
        $others = $character->versions()->where('version_hash', '!=', $character->version_hash)->get();

        foreach ($others->take(max(0, $others->count() - ($limit - 1))) as $old) {
            $old->delete();
        }
    }
}
