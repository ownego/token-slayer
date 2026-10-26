<?php

namespace App\Enums;

/**
 * What confirming a minion draft did to the player's set. String-backed so a
 * caller can hand it to the page as is.
 */
enum CustomMinionOutcome: string
{
    /**
     * The minion took a free slot: the set grew by one.
     */
    case Added = 'added';

    /**
     * The minion took over the slot of an earlier one, keeping its name and tick.
     */
    case Replaced = 'replaced';

    /**
     * The very same minion was already in the set: nothing changed.
     */
    case AlreadyInSet = 'already-in-set';
}
