<?php

namespace App\Enums;

/**
 * Which kind of custom sprite a `custom_sprites` row is. String-backed because
 * the value persists on `custom_sprites.type`.
 */
enum CustomSpriteType: string
{
    /**
     * A player's own fighter, built from a pose sheet.
     */
    case Fighter = 'fighter';

    /**
     * One of a player's custom minions.
     */
    case Minion = 'minion';
}
