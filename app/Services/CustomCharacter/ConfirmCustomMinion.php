<?php

namespace App\Services\CustomCharacter;

use App\Enums\CustomMinionSource;
use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterStorageException;
use App\Exceptions\CustomMinionDraftMissingException;
use App\Exceptions\CustomMinionSetFullException;
use App\Models\CustomMinion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adds a player's previewed minion to their set. It only ever acts on the draft
 * of the player who asks (never one staged by someone else), and asking twice for
 * a minion already in the set (a double click) is harmless. A full set refuses and
 * keeps the draft; the files of the minion are moved into place before the
 * database change and nothing is deleted until that change is in.
 */
class ConfirmCustomMinion
{
    /**
     * Create the action.
     *
     * @param  CustomMinionStore  $store  owns the minions' files
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @param  AnnounceCustomMinions  $announce  tells the battlefield
     * @return void
     */
    public function __construct(
        private readonly CustomMinionStore $store,
        private readonly CustomCharacterLock $lock,
        private readonly AnnounceCustomMinions $announce,
    ) {}

    /**
     * Adds the draft with the given version hash to the player's set.
     *
     * @param  User  $user  the owner
     * @param  string  $hash  the version hash of the draft the player previewed
     * @return CustomMinion the player's minion, now in the set
     *
     * @throws CustomMinionDraftMissingException when the player has no such draft
     * @throws CustomMinionSetFullException when the player already holds the most minions they can
     * @throws CustomCharacterStorageException when the staged files cannot be moved into place
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user, string $hash): CustomMinion
    {
        $minion = $this->lock->within($user->id, fn (): CustomMinion => $this->confirm($user, $hash));

        if ($minion->wasRecentlyCreated) {
            $this->announce->execute($user);
        }

        return $minion;
    }

    /**
     * Does the add while the player's lock is held.
     *
     * @param  User  $user  the owner
     * @param  string  $hash  the version hash of the draft
     * @return CustomMinion
     */
    private function confirm(User $user, string $hash): CustomMinion
    {
        $draft = $user->customMinionDraft()->first();
        $existing = $user->customMinions()->where('version_hash', $hash)->first();

        if ($existing !== null) {
            $this->dropDraftOf($user, $hash);

            return $existing;
        }

        if ($draft === null || $draft->version_hash !== $hash) {
            throw new CustomMinionDraftMissingException;
        }

        if ($user->customMinions()->count() >= (int) config('token_slayer.custom_character.minions_max')) {
            throw new CustomMinionSetFullException;
        }

        $this->store->promote($user->id, $hash);

        $minion = DB::transaction(function () use ($user, $draft, $hash): CustomMinion {
            $minion = $user->customMinions()->create([
                'version_hash' => $hash,
                'animations' => $draft->animations,
                'body_px' => $draft->body_px,
                'source' => CustomMinionSource::Upload,
            ]);
            $draft->delete();

            return $minion;
        });

        $this->store->prune($user->id, $user->customMinions()->pluck('version_hash')->all());

        return $minion;
    }

    /**
     * Drops the player's draft when it is the very minion already in the set (the
     * fighter was added after the same sheet was built), so no preview is left that
     * could never be added.
     *
     * @param  User  $user  the owner
     * @param  string  $hash  the version hash that is already in the set
     * @return void
     */
    private function dropDraftOf(User $user, string $hash): void
    {
        $draft = $user->customMinionDraft()->first();

        if ($draft !== null && $draft->version_hash === $hash) {
            $draft->delete();
            $this->store->discardStaged($user->id);
        }
    }
}
