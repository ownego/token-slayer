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
            'knight-templar', 'lancer', 'wizard', 'priest', 'skeleton-archer',
            'iron-man', 'captain-america', 'batman', 'superman',
        ]);
});

test('forUserAndBoss assigns characters deterministically and rotates per boss', function () {
    expect(FighterCharacter::forUserAndBoss(3, 7))->toBe(FighterCharacter::cases()[(3 + 7) % 24])
        ->and(FighterCharacter::forUserAndBoss(3, 7))->toBe(FighterCharacter::forUserAndBoss(3, 7))
        ->and(FighterCharacter::forUserAndBoss(3, null))->toBe(FighterCharacter::cases()[3 % 24]);
});

test('heroes join the assignment pool after the twenty roster fighters', function (int $userId, ?int $bossId, string $expected) {
    expect(FighterCharacter::forUserAndBoss($userId, $bossId)->value)->toBe($expected);
})->with([
    'first hero' => [20, 0, 'iron-man'],
    'last hero' => [17, 6, 'superman'],
    'wraps back to the roster' => [24, null, 'soldier'],
]);

test('every hero attacks with the hero style', function () {
    foreach (['iron-man', 'captain-america', 'batman', 'superman'] as $hero) {
        expect(FighterCharacter::from($hero)->attackType())->toBe('hero');
    }
});
