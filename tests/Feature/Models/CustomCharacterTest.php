<?php

use App\Models\CustomCharacter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the broadcast state names the version, a same-origin url base and the animation table', function () {
    $character = CustomCharacter::factory()->create(['version_hash' => 'abc123def456']);

    expect($character->broadcastState())->toBe([
        'version' => 'abc123def456',
        'url_base' => "/storage/custom-characters/{$character->user_id}/abc123def456",
        'animations' => $character->animations,
    ]);
});

test('a user with no custom character has no broadcast state', function () {
    expect(User::factory()->create()->customFighterState())->toBeNull();
});

test('a user with a custom character exposes its broadcast state', function () {
    $character = CustomCharacter::factory()->create();

    expect($character->user->customFighterState())->toBe($character->broadcastState());
});

test('a custom character is equipped when it is built', function () {
    expect(CustomCharacter::factory()->create()->active)->toBeTrue();
});

test('an unequipped custom character is not what the battlefield shows', function () {
    $character = CustomCharacter::factory()->create(['active' => false]);

    expect($character->user->customFighterState())->toBeNull()
        ->and($character->broadcastState())->toBeArray();
});

test('on a remote disk the broadcast url_base is the full url of the strips folder', function () {
    config([
        'token_slayer.custom_character.disk' => 'cdn',
        'filesystems.disks.cdn' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'auto', 'bucket' => 'b', 'endpoint' => 'https://r2.example.test', 'url' => 'https://media.example.test'],
    ]);
    $character = CustomCharacter::factory()->create(['version_hash' => 'abc123def456']);

    expect($character->broadcastState()['url_base'])->toBe("https://media.example.test/custom-characters/{$character->user_id}/abc123def456");
});
