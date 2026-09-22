<?php

use App\Enums\BossCharacter;

test('boss characters match the battlefield JS config keys in cycle order', function () {
    // Values and order must match BOSS_TYPES in resources/js/battlefield/config/bosses.js;
    // the client picks the sprite as BOSS_TYPES[number % length], so a mismatch here
    // names a boss after a sprite it is not wearing.
    expect(array_column(BossCharacter::cases(), 'value'))
        ->toBe([
            'boss-ghost', 'boss-skeleton', 'boss-abyssal-dreadknight', 'boss-slime',
            'boss-flying-demon', 'boss-minotaur', 'boss-demon-slime', 'boss-thanos',
        ]);
});

test('forNumber cycles through the roster exactly like Boss.bossTypeFor', function () {
    expect(BossCharacter::forNumber(7))->toBe(BossCharacter::Thanos)
        ->and(BossCharacter::forNumber(15))->toBe(BossCharacter::Thanos)
        ->and(BossCharacter::forNumber(1))->toBe(BossCharacter::Skeleton)
        ->and(BossCharacter::forNumber(8))->toBe(BossCharacter::Ghost);
});

test('a recognizable character carries its own fixed name', function () {
    expect(BossCharacter::Thanos->fixedName())->toBe('ThaNode');
});

test('generic monsters carry no fixed name', function (string $key) {
    expect(BossCharacter::from($key)->fixedName())->toBeNull();
})->with([
    'ghost' => 'boss-ghost',
    'skeleton' => 'boss-skeleton',
    'abyssal dreadknight' => 'boss-abyssal-dreadknight',
    'slime' => 'boss-slime',
    'flying demon' => 'boss-flying-demon',
    'minotaur' => 'boss-minotaur',
    'demon slime' => 'boss-demon-slime',
]);
