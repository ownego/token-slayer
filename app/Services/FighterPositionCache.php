<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

class FighterPositionCache
{
    public function __construct(private CacheRepository $cache) {}

    public function put(int $userId, float $x, float $y): void
    {
        $this->cache->put(
            $this->key($userId),
            ['x' => $x, 'y' => $y],
            now()->addMinutes(config('game.idle_minutes')),
        );
    }

    /**
     * @return array{x: float, y: float}|null
     */
    public function get(int $userId): ?array
    {
        return $this->cache->get($this->key($userId));
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, array{x: float, y: float}|null>
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
        return "fighter-position:{$userId}";
    }
}
