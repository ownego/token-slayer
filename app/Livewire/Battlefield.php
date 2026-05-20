<?php

namespace App\Livewire;

use App\Models\Boss;
use App\Models\Event;
use App\Models\User;
use App\Services\BossArena;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Battlefield extends Component
{
    public Boss $boss;

    public $fighters = [];

    public function mount(BossArena $arena): void
    {
        $this->boss = $arena->current();
        $this->fighters = User::where('last_event_at', '>=', now()->subMinutes(config('game.idle_minutes')))
            ->get();
    }

    /**
     * @return array<int, array{userId: int, handle: ?string, damage: int}>
     */
    public function leaderboardForCurrentBoss(): array
    {
        return Event::query()
            ->where('boss_id', $this->boss->id)
            ->select('user_id', DB::raw('SUM(tokens) as damage'))
            ->groupBy('user_id')
            ->orderByDesc('damage')
            ->with('user:id,slack_handle')
            ->get()
            ->map(fn (Event $row) => [
                'userId' => (int) $row->user_id,
                'handle' => $row->user?->slack_handle,
                'damage' => (int) $row->damage,
            ])
            ->all();
    }

    public function render()
    {
        return view('livewire.battlefield');
    }
}
