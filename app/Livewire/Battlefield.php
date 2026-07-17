<?php

namespace App\Livewire;

use App\Events\FighterMoved;
use App\Models\Boss;
use App\Models\Event;
use App\Models\User;
use App\Services\BossArena;
use App\Services\DamageTotals;
use App\Services\FighterChargingCache;
use App\Services\FighterPositionCache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

class Battlefield extends Component
{
    public Boss $boss;

    public $fighters = [];

    /** @var array<int, array{activity: ?string, started_at: string}|null> */
    protected array $chargingByUser = [];

    /** @var array<int, array{x: float, y: float}|null> */
    protected array $positionsByUser = [];

    /**
     * Memoized leaderboard rows, so the boot payload's leaderboard and damage
     * totals derive from a single query.
     *
     * @var array<int, array{userId: int, handle: ?string, damage: int}>|null
     */
    protected ?array $leaderboardRows = null;

    public function mount(BossArena $arena, FighterChargingCache $chargingCache, FighterPositionCache $positionCache): void
    {
        $this->boss = $arena->current();
        $this->fighters = User::where('last_event_at', '>=', now()->subMinutes(config('game.idle_minutes')))
            ->get();
        $userIds = $this->fighters->pluck('id')->all();
        $this->chargingByUser = $chargingCache->many($userIds);
        $this->positionsByUser = $positionCache->many($userIds);
    }

    /**
     * Move the authenticated user's fighter to normalized coordinates.
     *
     * Every accepted move is persisted, so the stored position always matches
     * the fighter the player last saw; a client-side debounce bounds the rate.
     *
     * @param  float  $x  Normalized x in [0.02, 0.98]
     * @param  float  $y  Normalized y in [0.02, 0.98]
     * @return void
     */
    #[Renderless]
    #[On('fighter-move')]
    public function move(float $x, float $y): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        if ($x < 0.02 || $x > 0.98 || $y < 0.02 || $y > 0.98) {
            return;
        }

        app(FighterPositionCache::class)->put($user->id, $x, $y);

        FighterMoved::dispatch($user, $x, $y);
    }

    /**
     * @return array<int, array{userId: int, handle: ?string, damage: int}>
     */
    public function leaderboardForCurrentBoss(): array
    {
        return $this->leaderboardRows ??= Event::query()
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

    /**
     * Per-user damage against the current boss, as [userId, damage] pairs.
     *
     * Drives each fighter's damage-grown sprite size. Mirrors the shape
     * snapshotState() emits, so a page load and a scene reboot seed identical
     * sizes rather than a reload silently shrinking everyone back to base.
     *
     * @return array<int, array<int, int>>
     */
    public function damageTotalsForCurrentBoss(): array
    {
        return array_map(
            fn (array $row) => [$row['userId'], $row['damage']],
            $this->leaderboardForCurrentBoss(),
        );
    }

    /**
     * @return array{allTime:int, monthly:int, daily:int, hourly:int}
     */
    public function globalDamage(): array
    {
        return app(DamageTotals::class)->global();
    }

    public function render()
    {
        return view('livewire.battlefield');
    }
}
