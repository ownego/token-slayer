<?php

namespace App\Services\CustomCharacter;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The one place a player's minion pool is written: every change to it (saving the ticks,
 * adding, replacing or removing a minion, an admin removing one) ends here as a single
 * UPDATE of `users.minion_pool` that moves the revision up by one.
 *
 * The caller holds the player's lock (`CustomCharacterLock::within()`), which is what stops two
 * tabs from interleaving; the lock is not taken again here because it is not re-entrant. What is
 * changed is worked out from the column read afresh, never from a `User` model the request loaded
 * earlier, and the write is a query update, never `$user->save()` or `$user->increment()`: a model
 * loaded before another tab saved can hold a value that equals the new one, so saving it writes
 * nothing while the revision still moves (or writes the old value back).
 */
class MinionPoolWriter
{
    /**
     * Applies a change to the player's selection and stores it with the revision moved up by one.
     *
     * @param  int  $userId  the player
     * @param  Closure(MinionPoolSelection): ?MinionPoolSelection  $change  gets the stored selection and returns the one to store (its revision is replaced), or null to leave the pool as it is
     * @return bool whether the pool was written
     */
    public function write(int $userId, Closure $change): bool
    {
        return DB::transaction(function () use ($userId, $change): bool {
            $current = MinionPoolSelection::fromColumn(User::query()->whereKey($userId)->value('minion_pool'));
            $next = $change($current);

            if ($next === null) {
                return false;
            }

            User::query()->whereKey($userId)->update([
                'minion_pool' => json_encode($next->withRev($current->rev + 1)->toColumn(), JSON_THROW_ON_ERROR),
            ]);

            return true;
        });
    }
}
