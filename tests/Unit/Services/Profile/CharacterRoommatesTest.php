<?php

use App\Models\User;
use App\Services\Profile\CharacterRoommates;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

test('groups users by their equipped character', function () {
    Cache::flush();
    User::factory()->create(['equipped_character' => 'orc-rider']);
    User::factory()->create(['equipped_character' => 'orc-rider']);
    User::factory()->create(['equipped_character' => 'wizard']);
    User::factory()->create(['equipped_character' => null]);

    $roommates = app(CharacterRoommates::class)->for();

    expect($roommates['orc-rider'])->toHaveCount(2);
    expect($roommates['wizard'])->toHaveCount(1);
    expect($roommates)->not->toHaveKey('');
});

test('carries the real handle, not the #id fallback (the query must select the columns displayHandle() reads)', function () {
    Cache::flush();
    $user = User::factory()->create(['equipped_character' => 'orc-rider', 'slack_handle' => 'jacky19']);

    $roommates = app(CharacterRoommates::class)->for();

    expect($roommates['orc-rider'][0]['handle'])->toBe('jacky19');
});

test('caches the grouping under the registered key', function () {
    Cache::flush();
    User::factory()->create(['equipped_character' => 'orc-rider']);

    app(CharacterRoommates::class)->for();

    expect(Cache::has(CacheKeys::CHARACTER_ROOMMATES))->toBeTrue();
});

test('each teammate carries their real avatar URL, so the roster shows faces, not initials', function () {
    Cache::flush();
    $mate = User::factory()->create(['equipped_character' => 'wizard', 'avatar_url' => 'https://avatars.slack-edge.com/m_512.png']);

    $roommates = app(CharacterRoommates::class)->for();

    expect($roommates['wizard'][0]['avatar'])->toBe($mate->avatarProxyUrl());
});
