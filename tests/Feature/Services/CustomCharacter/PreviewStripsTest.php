<?php

use App\Models\CustomCharacter;
use App\Models\User;
use App\Services\CustomCharacter\PreviewStrips;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

test('a player with no draft has no draft payload', function () {
    expect(app(PreviewStrips::class)->draftPayload(User::factory()->create()))->toBeNull();
});

test('a draft payload carries the hash and the staged spec, named like the player\'s fighter', function () {
    $character = CustomCharacter::factory()->create(['name' => 'Sir Pixel']);
    $draft = stagedFor($character->user);

    $payload = app(PreviewStrips::class)->draftPayload($character->user);

    expect($payload['hash'])->toBe($draft->version_hash)
        ->and($payload['spec'])->toMatchArray(['version' => $draft->version_hash, 'name' => 'Sir Pixel'])
        ->and($payload['spec']['url_base'])->toContain('staged')
        ->and($payload['spec']['animations'])->toBe($draft->animations);
});

test('a draft of a player with no fighter yet is named by default', function () {
    $user = User::factory()->create();
    stagedFor($user);

    expect(app(PreviewStrips::class)->draftPayload($user)['spec']['name'])->toBe(config('token_slayer.custom_character.default_name'));
});
