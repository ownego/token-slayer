<?php

namespace App\Services;

use App\Models\FighterPosition;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class FighterPositionCache
{
    public function __construct(private CacheRepository $cache) {}

    public function put(int $userId, float $x, float $y): void
    {
        $this->cache->forever($this->key($userId), ['x' => $x, 'y' => $y]);
        FighterPosition::query()->updateOrCreate(['user_id' => $userId], ['x' => $x, 'y' => $y]);
    }

    /**
     * @return array{x: float, y: float}|null
     */
    public function get(int $userId): ?array
    {
        $cached = $this->cache->get($this->key($userId));
        if ($cached !== null) {
            return $cached;
        }

        $row = FighterPosition::query()->find($userId);
        if ($row === null) {
            return null;
        }

        $position = ['x' => $row->x, 'y' => $row->y];
        $this->cache->forever($this->key($userId), $position);

        return $position;
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

        $missingIds = array_values(array_filter(
            $userIds,
            fn (int $id) => ($raw[$this->key($id)] ?? null) === null,
        ));

        $fromDb = $missingIds === []
            ? collect()
            : FighterPosition::query()->whereIn('user_id', $missingIds)->get()->keyBy('user_id');

        $out = [];
        foreach ($userIds as $id) {
            $cached = $raw[$this->key($id)] ?? null;
            if ($cached !== null) {
                $out[$id] = $cached;

                continue;
            }

            $row = $fromDb->get($id);
            if ($row === null) {
                $out[$id] = null;

                continue;
            }

            $position = ['x' => $row->x, 'y' => $row->y];
            $this->cache->forever($this->key($id), $position);
            $out[$id] = $position;
        }

        return $out;
    }

    private function key(int $userId): string
    {
        return "fighter-position:{$userId}";
    }
}
