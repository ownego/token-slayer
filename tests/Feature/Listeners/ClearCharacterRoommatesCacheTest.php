<?php

use App\Events\FighterCharacterChanged;
use App\Models\User;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

test('clears the character-roommates cache when a user re-equips', function () {
    Cache::put(CacheKeys::CHARACTER_ROOMMATES, ['stale' => 'data'], now()->addHour());
    $user = User::factory()->create();

    FighterCharacterChanged::dispatch($user);

    expect(Cache::has(CacheKeys::CHARACTER_ROOMMATES))->toBeFalse();
});
