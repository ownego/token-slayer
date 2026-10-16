<?php

use App\Services\CustomCharacter\CustomCharacterLock;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

test('the player lock holds for the configured lifetime', function () {
    config(['token_slayer.custom_character.lock_hold_seconds' => 150]);
    $lock = Mockery::mock();
    $lock->shouldReceive('block')->once()->with(config('token_slayer.custom_character.lock_seconds'));
    $lock->shouldReceive('release')->once();
    Cache::shouldReceive('lock')->once()->with(CacheKeys::customCharacterBuild(7), 150)->andReturn($lock);

    expect(app(CustomCharacterLock::class)->within(7, fn (): string => 'done'))->toBe('done');
});
