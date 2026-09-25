<?php

use App\Enums\BossCharacter;
use App\Models\Boss;

test('recognizable characters are keyed by their battlefield sprite', function () {
    // Values must be BOSS_TYPES keys in resources/js/battlefield/config/bosses.js
    // whose entry carries the same fixedName; config.test.js pins the JS side.
    expect(array_column(BossCharacter::cases(), 'value'))->toBe(['boss-thanos']);
});

test('a recognizable character carries its own fixed name', function () {
    expect(BossCharacter::Thanos->fixedName())->toBe('ThaNode');
});

test('a boss is recognized by the name it was spawned with, whatever its number', function (int $number, ?string $name, ?BossCharacter $expected) {
    expect(BossCharacter::of(Boss::factory()->make(['number' => $number, 'name' => $name])))->toBe($expected);
})->with([
    'ThaNode in an arbitrary slot' => [3, 'ThaNode', BossCharacter::Thanos],
    'pool name in the old thanos slot' => [55, 'Smaug', null],
    'unnamed legacy boss' => [7, null, null],
]);

test('a character is due only once the last seven bosses all went without it', function (array $recentNewestFirst, bool $due) {
    expect(BossCharacter::Thanos->isDueAfter($recentNewestFirst))->toBe($due);
})->with([
    'fresh arena' => [[], false],
    'six bosses so far' => [['A', 'B', 'C', 'D', 'E', 'F'], false],
    'seven generic bosses' => [['A', 'B', 'C', 'D', 'E', 'F', 'G'], true],
    'seven generic, older history ignored' => [['A', 'B', 'C', 'D', 'E', 'F', 'G', 'ThaNode'], true],
    'ThaNode six bosses ago' => [['A', 'B', 'C', 'D', 'E', 'ThaNode', 'G'], false],
    'unnamed legacy bosses count as generic' => [[null, null, null, null, null, null, null], true],
]);

test('a recognizable character announces what it spawns holding', function () {
    $boss = Boss::factory()->thanode()->make(['spawned_at' => now()]);

    expect(BossCharacter::Thanos->spawnFlavor($boss))->toBe('holding the Power Stone');
});
