<?php

use App\Enums\FighterCharacter;

test('fighter characters match the battlefield JS config keys in assignment order', function () {
    // Values and order must match FIGHTER_TYPES in resources/js/battlefield/config.js;
    // order matters because character assignment is (user_id + boss_id) % count.
    expect(array_column(FighterCharacter::cases(), 'value'))
        ->toBe([
            'soldier', 'knight', 'swordsman', 'axeman', 'orc',
            'armored-orc', 'elite-orc', 'skeleton', 'armored-skeleton', 'slime',
            'archer', 'werewolf', 'werebear', 'orc-rider', 'greatsword-skeleton',
        ]);
});

test('forUserAndBoss assigns characters deterministically and rotates per boss', function () {
    expect(FighterCharacter::forUserAndBoss(3, 7))->toBe(FighterCharacter::cases()[(3 + 7) % 15])
        ->and(FighterCharacter::forUserAndBoss(3, 7))->toBe(FighterCharacter::forUserAndBoss(3, 7))
        ->and(FighterCharacter::forUserAndBoss(3, null))->toBe(FighterCharacter::cases()[3 % 15]);
});
