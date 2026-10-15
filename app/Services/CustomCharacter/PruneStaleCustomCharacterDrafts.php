<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Models\CustomCharacterDraft;

/**
 * Removes drafts nobody went on to use: a built fighter that is previewed and then
 * forgotten would otherwise keep its staged files for ever. A player with a change
 * of theirs running is skipped and caught by the next pass; the fighter in use and
 * its confirmed files are never touched.
 */
class PruneStaleCustomCharacterDrafts
{
    /**
     * Create the action.
     *
     * @param  DiscardCustomCharacterDraft  $discard  removes one player's draft and its files
     * @return void
     */
    public function __construct(private readonly DiscardCustomCharacterDraft $discard) {}

    /**
     * Removes every draft older than the configured time limit.
     *
     * @return int how many drafts were removed
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

        return $removed;
    }
}
