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
    case KnightTemplar = 'knight-templar';
    case Lancer = 'lancer';
    case Wizard = 'wizard';
    case Priest = 'priest';
    case SkeletonArcher = 'skeleton-archer';

    public static function forUserAndBoss(int $userId, ?int $bossId): self
    {
        $cases = self::cases();

        return $cases[($userId + (int) $bossId) % count($cases)];
    }

    /**
     * The fighting style this character attacks with — mirrors
     * `attackType` in resources/js/battlefield/config/fighters.js's
     * FIGHTER_TYPES 1:1; `tests/Unit/Enums/FighterCharacterAttackTypeTest.php`
     * locks the two in sync.
     *
     * @return string one of 'slash'|'blast'|'shuriken'|'blade'|'arrow'
     */
    public function attackType(): string
    {
        return match ($this) {
            self::Soldier, self::Swordsman, self::Axeman, self::Orc, self::Werewolf => 'slash',
            self::Knight, self::ArmoredOrc, self::ArmoredSkeleton, self::GreatswordSkeleton,
            self::KnightTemplar, self::Lancer => 'blade',
            self::EliteOrc, self::Slime, self::Werebear, self::Wizard, self::Priest => 'blast',
            self::Skeleton => 'shuriken',
            self::Archer, self::OrcRider, self::SkeletonArcher => 'arrow',
        };
    }
}
