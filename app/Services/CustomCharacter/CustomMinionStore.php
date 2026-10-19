<?php

namespace App\Services\CustomCharacter;

/**
 * Owns where a player's custom minions' strips live. The same store as the
 * fighter's, rooted at `custom-minions/` instead of `custom-characters/`, so the
 * fighter store's sweeps (prune, discard, forget) and the orphan sweep can never
 * reach a minion's files, and the other way round.
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
