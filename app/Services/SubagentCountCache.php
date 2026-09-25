<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Per-user presence tracking for subagents dispatched via the Task tool,
 * driving the battlefield's cosmetic minion swarm. Cosmetic-only — never
 * gates damage or the event ledger; every Redis call is wrapped fail-soft
 * (see `safely()`) so a Redis outage degrades this feature silently instead
 * of breaking real event ingestion.
 *
 * Backed by individual Redis keys (`subagent:presence:{userId}:{agentId}`,
 * or `:pending:{token}` before an agent_id is known — see recordDispatch),
 * one per tracked subagent, each carrying its own `game.subagent_idle_seconds`
 * TTL that is refreshed on every activity signal. A subagent going quiet
 * needs no manual "is this stale" check or sweep-driven deletion — Redis
 * expires the key itself once nothing refreshes it in time. This replaced
 * two earlier designs: a plain cache counter (decremented on every "stop"-
 * shaped hook event, which a single subagent can fire more than once over
 * its own lifetime — verified live 2026-09-23 draining real dispatches to
 * zero long before they were actually done) and a `subagent_dispatches` DB
 * table mirroring the same presence model with manual timestamp/cutoff
 * comparisons — replaced 2026-09-24 once hook v7's per-tool-call heartbeat
 * made the write volume (one row UPDATE per tracked tool call) genuinely
 * heavy for data that only ever needs to survive `subagent_idle_seconds`.
 *
 * TTL alone only handles removal — an already-open browser still needs a
 * broadcast to shrink its live minion swarm, which nothing fires on its own
 * when a key silently expires. `sweepAllStale()` (called by
 * `fighters:sweep-idle`) is what notices a user's live count has dropped
 * since the last time it was seen and returns just the users worth
 * broadcasting for.
 */
class SubagentCountCache
{
    /**
     * @var string
     */
    private const string PRESENCE_PREFIX = 'subagent:presence:';

    /**
     * A Redis SET of every user id ever seen by recordDispatch/recordActivity,
     * added to (idempotent, cheap — SADD of an existing member is a no-op)
     * on every write, never removed. Presence KEYS alone can't drive
     * sweepAllStale()'s "who might need a broadcast" scan: once a user's
     * last presence key expires there is nothing left matching
     * `subagent:presence:{userId}:*` to find them by — this set is what
     * still lets the sweep notice their count just dropped to zero.
     *
     * @var string
     */
    private const string KNOWN_USERS_KEY = 'subagent:known-users';

    /**
     * Cache key (not Redis presence data — the generic Cache facade, so this
     * alone still works even when the presence calls below are failing soft)
     * remembering the last count actually broadcast for a user, so
     * sweepAllStale() only re-broadcasts when it has genuinely changed.
     *
     * @var string
     */
    private const string LAST_BROADCAST_PREFIX = 'subagent-count:last-broadcast:';

    public function __construct(private CacheRepository $cache) {}

    /**
     * Records a fresh Task/Agent dispatch with no agent_id known yet.
     *
     * @param  int  $userId
     * @return array{count: int, seq: int} the user's new total and a monotonic sequence number for this change
     */
    public function recordDispatch(int $userId): array
    {
        return $this->safely(function () use ($userId) {
            $key = $this->presenceKey($userId, 'pending:'.Str::random(20));
            Redis::setex($key, $this->ttl(), 1);
            Redis::sadd(self::KNOWN_USERS_KEY, $userId);

            return $this->result($userId);
        }, ['count' => 0, 'seq' => 0]);
    }

    /**
     * Records activity from a specific, identified subagent — either
     * refreshing its own already-claimed key's TTL, claiming the oldest
     * still-pending key left over from its original dispatch, or (if none
     * is pending, e.g. this agent's key already expired and it is now
     * re-emerging) creating a brand new key outright. Only the last of
     * these three actually changes the user's total, so it's the only one
     * that returns a broadcastable result — the first two return null, so a
     * caller on a high-frequency path (hook v7's PreToolUse/PostToolUse,
     * fired on every tool call a tracked subagent makes) never re-broadcasts
     * FighterAgentCountChanged with an unchanged count.
     *
     * @param  int  $userId
     * @param  string  $agentId
     * @return ?array{count: int, seq: int} the user's new total and a monotonic sequence number, or null when this call didn't change the count
     */
    public function recordActivity(int $userId, string $agentId): ?array
    {
        return $this->safely(function () use ($userId, $agentId) {
            Redis::sadd(self::KNOWN_USERS_KEY, $userId);
            $ownKey = $this->presenceKey($userId, $agentId);
            if (Redis::exists($ownKey)) {
                Redis::expire($ownKey, $this->ttl());

                return null;
            }

            $pendingKey = $this->oldestPendingKey($userId);
            if ($pendingKey !== null) {
                Redis::del($pendingKey);
                Redis::setex($ownKey, $this->ttl(), 1);

                return null;
            }

            Redis::setex($ownKey, $this->ttl(), 1);

            return $this->result($userId);
        }, null);
    }

    /**
     * @param  int  $userId
     * @return int the user's current total tracked subagents (pending + claimed), zero when none or on a Redis failure
     */
    public function get(int $userId): int
    {
        return $this->safely(fn () => count($this->presenceKeys($userId)), 0);
    }

    /**
     * Bulk read for seeding the battlefield's boot payload (see
     * Battlefield::mount()).
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int> every requested user id mapped to its current count, zero for one with nothing tracked
     */
    public function many(array $userIds): array
    {
        return collect($userIds)->mapWithKeys(fn (int $userId) => [$userId => $this->get($userId)])->all();
    }

    /**
     * Finds every user whose live presence-key count has changed since the
     * last time it was broadcast (almost always a drop — TTL expiry is the
     * only thing that removes a key, nothing here deletes anything) and
     * returns just those, each with a fresh result ready to broadcast.
     * Called by `fighters:sweep-idle` once a minute instead of a per-user
     * DB sweep: candidate users come from a single SMEMBERS read of
     * KNOWN_USERS_KEY (bounded by how many distinct users have EVER
     * dispatched a subagent, not how many are live right now) rather than
     * one query per user.
     *
     * @return array<int, array{count: int, seq: int}> keyed by user id, only for users whose count actually changed
     */
    public function sweepAllStale(): array
    {
        return $this->safely(function () {
            $changed = [];
            foreach (Redis::smembers(self::KNOWN_USERS_KEY) as $userId) {
                $userId = (int) $userId;
                $count = $this->get($userId);
                $lastKey = $this->lastBroadcastKey($userId);
                if ($this->cache->get($lastKey) === $count) {
                    continue;
                }
                $this->cache->put($lastKey, $count, now()->addSeconds($this->ttl() * 4));
                $changed[$userId] = ['count' => $count, 'seq' => $this->nextSeq($userId)];
            }

            return $changed;
        }, []);
    }

    /**
     * @param  int  $userId
     * @param  string  $suffix
     * @return string
     */
    private function presenceKey(int $userId, string $suffix): string
    {
        return self::PRESENCE_PREFIX."{$userId}:{$suffix}";
    }

    /**
     * @param  int  $userId
     * @return list<string> every presence key (pending or claimed) currently live for this user, safe to pass straight back into del()/exists()/expire()
     */
    private function presenceKeys(int $userId): array
    {
        return array_map(
            fn (string $key) => $this->ownKeyPortion($key),
            Redis::keys($this->presenceKey($userId, '*'))
        );
    }

    /**
     * Real phpredis returns KEYS results WITH the client's configured
     * REDIS_PREFIX still attached (unlike SETEX/EXISTS/EXPIRE/DEL, which
     * take and return unprefixed keys transparently) — caught live
     * 2026-09-24 on prod: feeding a raw KEYS-returned string straight back
     * into del()/exists() double-prefixes it, so it silently matches
     * nothing (recordActivity's "claim the oldest pending key" branch never
     * actually deleted the old key, leaking one stale-but-not-yet-expired
     * key per claim). This finds where this class's own key format begins
     * within whatever wrapper Redis put around it, rather than depending on
     * knowing the exact configured prefix string.
     *
     * @param  string  $key
     * @return string
     */
    private function ownKeyPortion(string $key): string
    {
        $pos = strpos($key, self::PRESENCE_PREFIX);

        return $pos === false ? $key : substr($key, $pos);
    }

    /**
     * @param  int  $userId
     * @return ?string the first still-pending key for this user, or null when none is outstanding
     */
    private function oldestPendingKey(int $userId): ?string
    {
        foreach ($this->presenceKeys($userId) as $key) {
            if (str_contains($key, ':pending:')) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return int
     */
    private function ttl(): int
    {
        return (int) config('game.subagent_idle_seconds');
    }

    /**
     * @param  int  $userId
     * @return string
     */
    private function lastBroadcastKey(int $userId): string
    {
        return self::LAST_BROADCAST_PREFIX.$userId;
    }

    /**
     * Runs a Redis-touching operation, catching any connection/command
     * failure and logging it instead of letting it bubble up — this
     * service's own contract is that it never breaks the caller (hook
     * ingestion, the boot payload, the idle sweep) just because Redis is
     * unreachable.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @param  T  $fallback
     * @return T
     */
    private function safely(callable $operation, mixed $fallback): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $e) {
            Log::warning('SubagentCountCache: Redis operation failed, degrading to no-op', ['error' => $e->getMessage()]);

            return $fallback;
        }
    }

    /**
     * @param  int  $userId
     * @return array{count: int, seq: int}
     */
    private function result(int $userId): array
    {
        return ['count' => $this->get($userId), 'seq' => $this->nextSeq($userId)];
    }

    /**
     * A monotonically increasing per-user number attached to every broadcast
     * change, so the client can discard a broadcast that arrives after a
     * fresher one it already applied — near-simultaneous concurrent
     * dispatches/claims racing on the same user have no guaranteed delivery
     * order over the wire, only a guaranteed order for when each one's
     * atomic increment here actually ran. Deliberately still on the generic
     * Cache facade (not the raw Redis presence keys above) — it already
     * works on whatever CACHE_STORE is configured (Redis on prod, database
     * on staging), and it's one small increment per broadcast, not the
     * per-tool-call write volume this refactor was about.
     *
     * @param  int  $userId
     * @return int
     */
    private function nextSeq(int $userId): int
    {
        $key = $this->seqKey($userId);
        $this->cache->add($key, 0, now()->addSeconds($this->ttl() * 2));
        $seq = $this->cache->increment($key);
        $this->cache->touch($key, now()->addSeconds($this->ttl() * 2));

        return $seq;
    }

    /**
     * @param  int  $userId
     * @return string
     */
    private function seqKey(int $userId): string
    {
        return "subagent-count:seq:{$userId}";
    }
}
