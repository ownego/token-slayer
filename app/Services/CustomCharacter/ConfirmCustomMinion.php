<?php

namespace App\Services\CustomCharacter;

use App\Enums\CustomMinionOutcome;
use App\Enums\CustomMinionSource;
use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterStorageException;
use App\Exceptions\CustomMinionDraftMissingException;
use App\Exceptions\CustomMinionSetFullException;
use App\Models\CustomMinion;
use App\Models\CustomMinionDraft;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Puts a player's previewed minion into their set. It only ever acts on the draft
 * of the player who asks (never one staged by someone else), and asking twice for
 * a minion already in the set (a double click) is harmless. A draft with a slot
 * replaces the minion there, keeping its slot, name and tick (the tick lives in the
 * player's pool, which a replace leaves as it is); one without takes the lowest free slot,
 * and the new minion is ticked into the pool. A full set refuses and keeps the draft. The files are copied
 * into place before the database change and the staged copy goes only after that
 * change is committed, so a failed insert or copy leaves the draft whole to retry.
 */
class ConfirmCustomMinion
{
    /**
     * Create the action.
     *
     * @param  CustomMinionStore  $store  owns the minions' files
     * @param  CustomCharacterLock  $lock  keeps one player's changes from interleaving
     * @param  AnnounceCustomMinions  $announce  tells the battlefield
     * @param  CustomMinionSlots  $slots  knows which slot is free and whether the set is full
     * @param  MinionPoolWriter  $pool  writes the pool's selection and moves its revision
     * @return void
     */
    public function __construct(
        private readonly CustomMinionStore $store,
        private readonly CustomCharacterLock $lock,
        private readonly AnnounceCustomMinions $announce,
        private readonly CustomMinionSlots $slots,
        private readonly MinionPoolWriter $pool,
    ) {}

    /**
     * Adds the draft with the given version hash to the player's set, or lets it
     * replace the minion its slot names.
     *
     * @param  User  $user  the owner
     * @param  string  $hash  the version hash of the draft the player previewed
     * @param  int|null|false  $aimedAt  the slot the preview was aimed at when the player saw it (null = it adds a minion); false skips the check
     * @return ConfirmedMinion the minion that holds the look, and what confirming did to the set
     *
     * @throws CustomMinionDraftMissingException when the player has no such draft, or it was aimed at another slot since they saw it
     * @throws CustomMinionSetFullException when the draft adds a minion and the player already holds the most they can
     * @throws CustomCharacterStorageException when the staged files cannot be copied into place
     * @throws CustomCharacterBusyException when another change of this player's is still running
     */
    public function execute(User $user, string $hash, int|null|false $aimedAt = false): ConfirmedMinion
    {
        $confirmed = $this->lock->within($user->id, fn (): ConfirmedMinion => $this->confirm($user, $hash, $aimedAt));

        if ($confirmed->outcome !== CustomMinionOutcome::AlreadyInSet) {
            $this->announce->execute($user);
        }

        return $confirmed;
    }

    /**
     * Does the confirm while the player's lock is held.
     *
     * @param  User  $user  the owner
     * @param  string  $hash  the version hash of the draft
     * @param  int|null|false  $aimedAt  the slot the player saw the preview aimed at, or false to skip the check
     * @return ConfirmedMinion
     */
    private function confirm(User $user, string $hash, int|null|false $aimedAt): ConfirmedMinion
    {
        // The relation may have been read before the lock was taken; the set is read afresh under it.
        $user->unsetRelation('customMinions');

        $existing = $user->customMinions()->where('version_hash', $hash)->first();

        if ($existing !== null) {
            $this->dropDraftOf($user, $hash);

            return new ConfirmedMinion($existing, CustomMinionOutcome::AlreadyInSet);
        }

        $draft = $user->customMinionDraft()->first();

        // The hash alone cannot tell two previews of the same look apart: another tab may have aimed it
        // at a different slot since this one showed it, and confirming would replace the wrong minion.
        if ($draft === null || $draft->version_hash !== $hash || ($aimedAt !== false && $draft->slot !== $aimedAt)) {
            throw new CustomMinionDraftMissingException;
        }

        $replaced = $draft->slot === null ? null : $user->customMinions()->where('slot', $draft->slot)->first();
        $slot = $replaced?->slot ?? $this->freeSlotFor($user, $draft);

        $this->store->copyStaged($user->id, $hash);

        $minion = DB::transaction(function () use ($user, $draft, $replaced, $slot): CustomMinion {
            $replaced?->delete();

            $minion = $user->customMinions()->create([
                'version_hash' => $draft->version_hash,
                'slot' => $slot,
                'name' => $replaced?->name ?? $this->nameFor($draft, $slot),
                'animations' => $draft->animations,
                'body_px' => $draft->body_px,
                'source' => $draft->source ?? CustomMinionSource::Upload,
            ]);
            $draft->delete();
            $this->pool->write($user->id, fn (MinionPoolSelection $selection): MinionPoolSelection => $replaced === null ? $selection->withSlot($slot, true) : $selection);

            return $minion;
        });

        $this->store->discardStaged($user->id);
        $this->store->prune($user->id, $user->customMinions()->pluck('version_hash')->all());

        return new ConfirmedMinion($minion, $replaced === null ? CustomMinionOutcome::Added : CustomMinionOutcome::Replaced);
    }

    /**
     * The slot a draft that adds a minion takes: the slot it was aimed at when that
     * slot has since been emptied (its minion removed in another tab), else the
     * lowest free one.
     *
     * @param  User  $user  the owner
     * @param  CustomMinionDraft  $draft  the draft being confirmed
     * @return int
     *
     * @throws CustomMinionSetFullException when the set has no room
     */
    private function freeSlotFor(User $user, CustomMinionDraft $draft): int
    {
        $slot = $draft->slot ?? $this->slots->firstFree($user);

        if ($slot === null || $this->slots->isFull($user)) {
            throw new CustomMinionSetFullException;
        }

        return $slot;
    }

    /**
     * What a minion new to its slot is called: the fighter's name for a copy of the
     * fighter, otherwise `Minion <slot>`.
     *
     * @param  CustomMinionDraft  $draft  the draft being confirmed
     * @param  int  $slot  the slot the minion takes
     * @return string
     */
    private function nameFor(CustomMinionDraft $draft, int $slot): string
    {
        return $draft->name ?? "Minion {$slot}";
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
