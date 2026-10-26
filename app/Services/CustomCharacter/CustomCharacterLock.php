<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterBusyException;
use App\Support\CacheKeys;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Serialises the changes one player makes to their custom sprites, the fighter and
 * the minions alike: they share one lock per player, because a minion can be
 * copied from the fighter and both keep files the other's sweep must not cut. The
 * rate limiter counts attempts, it does not stop two tabs from building at once,
 * and two builds that overlap can each delete the other's files and leave the
 * database pointing at a folder that is gone. Different players never wait on each other.
 */
class CustomCharacterLock
{
    /**
     * Runs the callback with the player's lock held, waiting a few seconds for it.
     *
     * @param  int  $userId  the player
     * @param  Closure  $callback  what to do while holding the lock
     * @return mixed whatever the callback returns
     *
     * @throws CustomCharacterBusyException when another change by the same player does not finish in time
     */
    public function within(int $userId, Closure $callback): mixed
    {
        $lock = Cache::lock(CacheKeys::customCharacterBuild($userId), (int) config('token_slayer.custom_character.lock_hold_seconds'));

        try {
            $lock->block((int) config('token_slayer.custom_character.lock_seconds'));
        } catch (LockTimeoutException) {
            throw new CustomCharacterBusyException;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
