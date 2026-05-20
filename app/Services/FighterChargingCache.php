<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

class FighterChargingCache
{
    public function __construct(private CacheRepository $cache) {}

    public function put(int $userId, string $activity): void
    {
        $this->cache->put(
            $this->key($userId),
            [
                'activity' => $activity,
                'started_at' => now()->toIso8601String(),
            ],
            now()->addMinutes(config('game.idle_minutes')),
        );
    }

    public function forget(int $userId): void
    {
        $this->cache->forget($this->key($userId));
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, array{activity: string, started_at: string}|null>
     */
    public function many(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $keys = array_map(fn (int $id) => $this->key($id), $userIds);
        $raw = $this->cache->many($keys);

        $out = [];
        foreach ($userIds as $id) {
            $out[$id] = $raw[$this->key($id)] ?? null;
        }

        return $out;
    }

    private function key(int $userId): string
    {
        return "fighter-charging:{$userId}";
    }
}
