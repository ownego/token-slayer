<?php

namespace App\Enums;

/**
 * How a custom minion came to be in a player's set. String-backed because the
 * value persists on `custom_minions.source`.
 */
enum CustomMinionSource: string
{
    /**
     * Built from a pose sheet the player uploaded for it.
     */
    case Upload = 'upload';

    /**
     * Copied from the player's custom fighter.
     */
    case Fighter = 'fighter';
}
