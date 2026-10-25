<?php

use App\Services\CustomCharacter\SpriteStripBuilder;

/**
 * The first number after a JavaScript constant's name in a source file.
 *
 * @param  string  $file  path under resources/js
 * @param  string  $constant  the constant's name
 * @return int
 */
function jsConstant(string $file, string $constant): int
{
    preg_match("/const {$constant} = (\\d+);/", file_get_contents(resource_path("js/{$file}")), $match);

    return (int) ($match[1] ?? -1);
}

test('the battlefield draws a custom fighter with the numbers the server built its strips with', function () {
    $file = 'battlefield/fighter/custom-fighter.js';

    expect(jsConstant($file, 'CUSTOM_BODY_PX'))->toBe((int) config('token_slayer.custom_character.sprite_height'))
        ->and(jsConstant($file, 'CUSTOM_FOOT_ROW'))->toBe(SpriteStripBuilder::FOOT_ROW)
        ->and(jsConstant($file, 'CUSTOM_FRAME'))->toBe(SpriteStripBuilder::FRAME);
});

test('Livewire turns a temporary upload away above the sheet size limit, before the build ever sees it', function () {
    $kilobytes = intdiv((int) config('token_slayer.custom_character.max_bytes'), 1024);

    expect(config('livewire.temporary_file_upload.rules'))->toContain("max:{$kilobytes}");
});

test('the custom fighter lock outlasts its acquisition wait', function () {
    expect(config('token_slayer.custom_character.lock_hold_seconds'))
        ->toBeInt()
        ->toBeGreaterThan(config('token_slayer.custom_character.lock_seconds'));
});

test('the server lists the same default minions as MINION_TYPES', function () {
    $source = file_get_contents(resource_path('js/battlefield/config/companions.js'));

    preg_match('/export const MINION_TYPES = \[(.*?)\n\];/s', $source, $block);
    preg_match_all("/^    key: '([^']+)'/m", $block[1] ?? '', $keys);

    expect($keys[1])->not->toBeEmpty()
        ->and(config('token_slayer.custom_character.default_minions'))->toBe($keys[1]);
});

test('the client caps a pool at the same number of custom minions the server lets a player hold', function () {
    expect(jsConstant('battlefield/config/companions.js', 'MINION_POOL_CUSTOM_CAP'))
        ->toBe((int) config('token_slayer.custom_character.minions_max'));
});
