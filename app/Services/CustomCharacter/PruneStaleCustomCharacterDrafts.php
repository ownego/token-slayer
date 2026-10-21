<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomMinionDraftMissingException;
use App\Models\CustomCharacterDraft;
use App\Models\CustomMinionDraft;

/**
 * Removes drafts nobody went on to use: a built fighter or minion that is previewed
 * and then forgotten would otherwise keep its staged files for ever. A player with a change
 * of theirs running is skipped and caught by the next pass; the fighter in use and
 * its confirmed files, and the minions in a set, are never touched.
 */
class PruneStaleCustomCharacterDrafts
{
    /**
     * Create the action.
     *
     * @param  DiscardCustomCharacterDraft  $discard  removes one player's fighter draft and its files
     * @param  DiscardCustomMinionDraft  $discardMinion  removes one player's minion draft and its files
     * @return void
     */
    public function __construct(
        private readonly DiscardCustomCharacterDraft $discard,
        private readonly DiscardCustomMinionDraft $discardMinion,
    ) {}

    /**
     * Removes every fighter and minion draft older than the configured time limit.
     *
     * @return int how many drafts were removed, of both kinds
     */
    public function execute(): int
    {
        $cutoff = now()->subHours((int) config('token_slayer.custom_character.draft_ttl_hours'));
        $removed = 0;

        CustomCharacterDraft::with('user')->where('updated_at', '<', $cutoff)->each(function (CustomCharacterDraft $draft) use (&$removed): void {
            try {
                $this->discard->execute($draft->user);
                $removed++;
            } catch (CustomCharacterBusyException) {
                // the player is mid-change: the next pass gets it
            }
        });

        CustomMinionDraft::with('user')->where('updated_at', '<', $cutoff)->each(function (CustomMinionDraft $draft) use (&$removed, $cutoff): void {
            try {
                $this->discardMinion->execute($draft->user, $draft->version_hash, $cutoff);
                $removed++;
            } catch (CustomCharacterBusyException) {
                // the player is mid-change: the next pass gets it
            } catch (CustomMinionDraftMissingException) {
                // the player built or touched the draft again before we got the lock: it is not stale any more
            }
        });

        return $removed;
    }
}
