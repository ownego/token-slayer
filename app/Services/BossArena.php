<?php

namespace App\Services;

use App\Models\Boss;

class BossArena
{
    public function current(): Boss
    {
        return Boss::where('status', 'alive')->orderByDesc('number')->first()
            ?? $this->spawnFirst();
    }

    public function lockedCurrent(): Boss
    {
        $boss = Boss::where('status', 'alive')
            ->orderByDesc('number')
            ->lockForUpdate()
            ->first();

        if ($boss !== null) {
            return $boss;
        }

        // No alive boss inside this transaction — ensure one exists, then re-lock.
        $this->current();

        return Boss::where('status', 'alive')
            ->orderByDesc('number')
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function spawnNext(): Boss
    {
        $latest = Boss::orderByDesc('number')->first();
        $number = ($latest?->number ?? 0) + 1;

        return $this->spawn($number);
    }

    private function spawnFirst(): Boss
    {
        return $this->spawn(1);
    }

    private function spawn(int $number): Boss
    {
        $hp = $number * config('game.base_hp');

        return Boss::create([
            'number' => $number,
            'max_hp' => $hp,
            'current_hp' => $hp,
            'status' => 'alive',
            'spawned_at' => now(),
        ]);
    }
}
