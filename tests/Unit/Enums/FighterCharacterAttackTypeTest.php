<?php

use App\Enums\FighterCharacter;

test('every fighter character has an attack type, matching FIGHTER_TYPES order', function () {
    // Read straight out of the JS source of truth so this test breaks the
    // moment PHP and JS drift, instead of re-encoding a second copy here.
    $js = file_get_contents(base_path('resources/js/battlefield/config/fighters.js'));
    preg_match_all("/key: '([a-z-]+)', attackType: AttackType\.([A-Z]+)/", $js, $matches, PREG_SET_ORDER);

    expect($matches)->not->toBeEmpty();

    foreach ($matches as [, $key, $jsConstant]) {
        $character = FighterCharacter::from($key);
        expect($character->attackType())->toBe(strtolower($jsConstant));
    }

    expect(count($matches))->toBe(count(FighterCharacter::cases()));
});
