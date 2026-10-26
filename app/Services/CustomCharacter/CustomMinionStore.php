<?php

namespace App\Services\CustomCharacter;

/**
 * Owns where a player's custom minions' strips live. The same store as the
 * fighter's, rooted at `custom-minions/` instead of `custom-characters/`, so the
 * store's own sweeps (prune, discard, forget) never reach the other kind's files.
 * The orphan sweep (PruneOrphanCustomCharacterFiles) does go into both roots, each
 * against its own references, and a player's draft sweep (PruneStaleCustomCharacterDrafts)
 * covers both kinds of draft.
 */
final class CustomMinionStore extends CustomCharacterStore
{
    /**
     * Root folder, on the disk, that holds every user's minions.
     *
     * @var string
     */
    public const string ROOT = 'custom-minions';
}
