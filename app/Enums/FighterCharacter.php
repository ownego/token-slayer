<?php

namespace App\Enums;

/**
 * Playable fighter characters, in assignment order. Values and order must
 * match FIGHTER_TYPES in resources/js/battlefield/config.js; order matters
 * because each user-boss pair is assigned a character via modulo.
 */
enum FighterCharacter: string
{
    case Soldier = 'soldier';
    case Knight = 'knight';
    case Swordsman = 'swordsman';
    case Axeman = 'axeman';
    case Orc = 'orc';
    case ArmoredOrc = 'armored-orc';
    case EliteOrc = 'elite-orc';
    case Skeleton = 'skeleton';
    case ArmoredSkeleton = 'armored-skeleton';
    case Slime = 'slime';
    case Archer = 'archer';
    case Werewolf = 'werewolf';
    case Werebear = 'werebear';
    case OrcRider = 'orc-rider';
    case GreatswordSkeleton = 'greatsword-skeleton';

    public static function forUserAndBoss(int $userId, ?int $bossId): self
    {
        $cases = self::cases();

        return $cases[($userId + (int) $bossId) % count($cases)];
    }
}
