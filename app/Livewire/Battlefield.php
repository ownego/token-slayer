<?php

namespace App\Livewire;

use App\Models\Boss;
use App\Models\Event;
use App\Models\User;
use App\Services\BossArena;
use App\Services\FighterChargingCache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Battlefield extends Component
{
    public Boss $boss;

    public $fighters = [];

    /** @var array<int, array{activity: ?string, started_at: string}|null> */
    protected array $chargingByUser = [];

    public function mount(BossArena $arena, FighterChargingCache $chargingCache): void
    {
        $this->boss = $arena->current();
        $this->fighters = User::where('last_event_at', '>=', now()->subMinutes(config('game.idle_minutes')))
            ->get();
        $this->chargingByUser = $chargingCache->many($this->fighters->pluck('id')->all());
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
            ->with('user:id,name,slack_handle,display_name')
            ->get()
            ->map(fn (Event $row) => [
                'userId' => (int) $row->user_id,
                'handle' => $row->user?->displayHandle(),
                'damage' => (int) $row->damage,
            ])
            ->all();
    }

    public function render()
    {
        return view('livewire.battlefield');
    }
}
