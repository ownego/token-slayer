<?php

namespace App\Services;

use App\Models\User;
use App\Support\DamageResult;
use Illuminate\Support\Facades\DB;

class DamageService
{
    public function __construct(private BossArena $arena) {}

    /**
     * Apply a token damage payload to the current boss, chaining kills if it overflows.
     *
     * The locked read + write happens inside a single DB transaction so concurrent
     * writers serialize on the current alive boss row. Damage that exceeds a boss's
     * remaining HP rolls over to the next-spawned boss, and so on, until the
     * payload is exhausted. Every boss defeated in the chain is appended to
     * `DamageResult::killedBosses` in kill order. A non-positive `$tokens` is a
     * no-op that returns the current boss and an empty `killedBosses` array.
     */
    public function apply(User $user, int $tokens): DamageResult
    {
        if ($tokens <= 0) {
            return new DamageResult($this->arena->current());
        }

        return DB::transaction(function () use ($user, $tokens) {
            $boss = $this->arena->lockedCurrent();

            $remaining = $tokens;
            $killedBosses = [];

            while ($remaining > 0) {
                if ($boss->current_hp > $remaining) {
                    $boss->current_hp -= $remaining;
                    $boss->save();
                    $remaining = 0;
                    break;
                }
                $remaining -= $boss->current_hp;
                $boss->current_hp = 0;
                $boss->status = 'defeated';
                $boss->defeated_at = now();
                $boss->killing_blow_user_id = $user->id;
                $boss->save();
                $killedBosses[] = $boss;
                $boss = $this->arena->spawnNext();
            }

            return new DamageResult($boss, $killedBosses);
        });
    }
}
