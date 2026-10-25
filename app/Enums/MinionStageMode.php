<?php

namespace App\Enums;

/**
 * What the Minions tab's stage shows for the cell the player picked, which also decides
 * the panel that goes with it.
 */
enum MinionStageMode: string
{
    /**
     * The preview that has no slot to sit in because the set filled up under it.
     */
    case Overflow = 'overflow';

    /**
     * One of the five default looks.
     */
    case Default = 'default';

    /**
     * A preview waiting in a slot, to add or discard.
     */
    case Draft = 'draft';

    /**
     * A slot with nothing in it.
     */
    case Empty = 'empty';

    /**
     * One of the player's own minions.
     */
    case Minion = 'minion';
}
