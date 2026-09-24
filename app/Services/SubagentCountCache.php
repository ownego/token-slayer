<?php

namespace App\Services;

use App\Models\SubagentDispatch;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-user presence tracking for subagents dispatched via the Task tool,
 * driving the battlefield's cosmetic minion swarm. Cosmetic-only — never
 * gates damage or the event ledger.
 *
 * Backed by the `subagent_dispatches` table (see its migration's docblock
 * for why), not a plain counter. An earlier version trusted every "stop"-
 * shaped hook event as a genuine "this subagent is done" signal and
 * decremented a raw cache counter on each one; verified live 2026-09-23 that
 * a single subagent can fire multiple such events over its own lifetime
 * whenever the harness backgrounds one of ITS tool calls, silently draining
 * a burst of real dispatches to zero long before any of them were actually
 * done. This version instead tracks each subagent's own last-seen activity
 * and only drops it once `subagents:sweep-idle` finds it stale past
 * `game.subagent_idle_seconds` — the same presence model `users.last_event_at`
 * + `fighters:sweep-idle` already use for the fighter roster itself, just a
 * much shorter window. A subagent that goes quiet and later sends another
 * event after its row was already pruned simply gets a fresh row (see
 * recordActivity) — the count rises again and the client replays the spawn/
 * summon ceremony, which is the desired "re-summon" behavior, not special-
 * cased anywhere.
 *
 * The `agent_id` that identifies a specific subagent is not known at
 * dispatch time (PreToolUse's payload never carries it — only an event
 * fired from within the subagent's own execution does), so recordDispatch
 * creates a "pending" row (`agent_id` null) and recordActivity claims the
 * oldest pending row for a newly-seen agent_id inside a transaction, so
 * concurrent dispatches/claims racing on the same user never lose a slot to
 * a lost update.
 */
class SubagentCountCache
{
    public function __construct(private CacheRepository $cache) {}

    /**
     * Records a fresh Task/Agent dispatch with no agent_id known yet.
     *
     * @param  int  $userId
     * @return array{count: int, seq: int} the user's new total and a monotonic sequence number for this change
     */
    public function recordDispatch(int $userId): array
    {
        return DB::transaction(function () use ($userId) {
            SubagentDispatch::create([
                'user_id' => $userId,
                'agent_id' => null,
                'last_seen_at' => now(),
            ]);

            return $this->result($userId);
        });
    }

    /**
     * Records activity from a specific, identified subagent — either
     * refreshing its own already-claimed row, claiming the oldest still-
     * pending row left over from its original dispatch, or (if none is
     * pending, e.g. this agent's row was already claimed-and-pruned and it
     * is now re-emerging) creating a brand new row outright. Only the last
     * of these three actually changes the user's total, so it's the only
     * one that returns a broadcastable result — the first two return null,
     * the same "nothing to broadcast" signal pruneStale already established,
     * so a caller on a high-frequency path (hook v7's PreToolUse/PostToolUse,
     * fired on every tool call a tracked subagent makes) never re-broadcasts
     * FighterAgentCountChanged with an unchanged count.
     *
     * @param  int  $userId
     * @param  string  $agentId
     * @return ?array{count: int, seq: int} the user's new total and a monotonic sequence number, or null when this call didn't change the count
     */
    public function recordActivity(int $userId, string $agentId): ?array
    {
        return DB::transaction(function () use ($userId, $agentId) {
            $own = SubagentDispatch::where('user_id', $userId)
                ->where('agent_id', $agentId)
                ->lockForUpdate()
                ->first();

            if ($own) {
                $own->update(['last_seen_at' => now()]);

                return null;
            }

            $pending = SubagentDispatch::where('user_id', $userId)
                ->whereNull('agent_id')
                ->oldest('created_at')
                ->lockForUpdate()
                ->first();

            if ($pending) {
                $pending->update(['agent_id' => $agentId, 'last_seen_at' => now()]);

                return null;
            }

            SubagentDispatch::create([
                'user_id' => $userId,
                'agent_id' => $agentId,
                'last_seen_at' => now(),
            ]);

            return $this->result($userId);
        });
    }

    /**
     * @param  int  $userId
     * @return int the user's current total tracked subagents (pending + claimed), zero when none
     */
    public function get(int $userId): int
    {
        return SubagentDispatch::where('user_id', $userId)->count();
    }

    /**
     * Bulk read for seeding the battlefield's boot payload (see
     * Battlefield::mount()) — one query for every requested user instead of
     * N, mirroring FighterChargingCache::many()/FighterPositionCache::many().
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int> every requested user id mapped to its current count, zero for one with nothing tracked
     */
    public function many(array $userIds): array
    {
        $counts = SubagentDispatch::whereIn('user_id', $userIds)
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        return collect($userIds)->mapWithKeys(fn (int $userId) => [$userId => (int) ($counts[$userId] ?? 0)])->all();
    }

    /**
     * Deletes this user's rows that have gone stale — `last_seen_at` past
     * `game.subagent_idle_seconds` with no event of any kind since. Called
     * by `subagents:sweep-idle`, never on a read path, so a subagent that
     * genuinely stops isn't required to send one final explicit "I'm done"
     * event nothing in the hook vocabulary actually guarantees exists.
     *
     * @param  int  $userId
     * @return ?array{count: int, seq: int} null when nothing was stale for this user, so the caller never broadcasts a no-op change
     */
    public function pruneStale(int $userId): ?array
    {
        return DB::transaction(function () use ($userId) {
            $deleted = SubagentDispatch::where('user_id', $userId)
                ->where('last_seen_at', '<', $this->idleCutoff())
                ->delete();

            if ($deleted === 0) {
                return null;
            }

            return $this->result($userId);
        });
    }

    /**
     * @return Carbon
     */
    private function idleCutoff(): Carbon
    {
        return now()->subSeconds(config('game.subagent_idle_seconds'));
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
     * atomic increment here actually ran.
     *
     * @param  int  $userId
     * @return int
     */
    private function nextSeq(int $userId): int
    {
        $key = $this->seqKey($userId);
        $this->cache->add($key, 0, now()->addSeconds(config('game.subagent_idle_seconds') * 2));
        $seq = $this->cache->increment($key);
        $this->cache->touch($key, now()->addSeconds(config('game.subagent_idle_seconds') * 2));

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
