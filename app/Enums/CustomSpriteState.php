<?php

namespace App\Enums;

/**
 * Where a `custom_sprites` row stands for its player. String-backed because the
 * value persists on `custom_sprites.state`.
 */
enum CustomSpriteState: string
{
    /**
     * Built and previewed, not yet confirmed. At most one per player and type.
     */
    case Draft = 'draft';

    /**
     * In use: the equipped fighter, or a minion holding a slot.
     */
    case Current = 'current';

    /**
     * A confirmed fighter version the player can switch back to.
     */
    case Previous = 'previous';
}
