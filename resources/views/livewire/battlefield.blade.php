<div class="relative min-h-screen bg-slate-950 text-white">
    <div
        id="battlefield-mount"
        data-battlefield-state="{{ json_encode([
            'boss' => [
                'number' => $boss->number,
                'name' => $boss->name,
                'currentHp' => $boss->current_hp,
                'maxHp' => $boss->max_hp,
            ],
            'fighters' => $fighters->map(fn ($f) => [
                'id' => $f->id,
                'handle' => $f->displayHandle(),
                'avatarUrl' => route('avatar', $f),
                'character' => $f->characterForBoss($boss->id),
                'charging' => $this->chargingByUser[$f->id] ?? null,
            ])->values(),
            'leaderboard' => $this->leaderboardForCurrentBoss(),
        ]) }}"
        class="fixed inset-0" style="background-color:#020617"
    >
        <div id="bf-loader" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;pointer-events:none">
            <div style="width:180px;height:4px;background:#1e293b;border-radius:2px;overflow:hidden">
                <div id="bf-loader-bar" style="height:100%;width:0%;background:#60a5fa;border-radius:2px;transition:width 0.2s ease"></div>
            </div>
            <span style="font-family:monospace;font-size:12px;color:#475569;letter-spacing:0.05em">Loading arena…</span>
        </div>
    </div>

    @unless (request('embed') === 'ide')
        <a
            href="{{ route('profile') }}"
            class="absolute left-3 top-3 z-10 rounded bg-slate-800/80 px-3 py-2 font-mono text-xs text-amber-300 ring-1 ring-amber-400/40"
        >
            Profile
        </a>
    @endunless

    <div
        x-data="battlefieldLeaderboardOverlay()"
        x-init="init()"
        class="bf-portrait-only"
    >
        <button
            type="button"
            @click="open = true"
            class="absolute right-3 top-3 z-10 rounded bg-slate-800/80 px-3 py-2 font-mono text-xs text-amber-300 ring-1 ring-amber-400/40"
        >
            <span x-show="!victory">Leaderboard</span>
            <span x-show="victory" class="text-amber-200">Victory</span>
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition.opacity
            @click.self="open = false"
            class="fixed inset-0 z-20 flex items-end bg-slate-950/80"
        >
            <div class="w-full rounded-t-xl bg-slate-900 p-4 ring-1 ring-slate-700">
                <div class="flex items-center justify-between pb-3">
                    <h2 class="font-mono text-sm uppercase tracking-wider text-amber-300">
                        <span x-show="!victory">Top damage dealers</span>
                        <template x-if="victory">
                            <span x-text="`${victory.bossLabel} defeated`"></span>
                        </template>
                    </h2>
                    <button type="button" @click="open = false; victory = null" class="text-xs text-slate-400">Close</button>
                </div>

                <template x-if="victory && victory.killerHandle">
                    <p class="pb-3 text-xs text-amber-200/80">
                        Killing blow: <span x-text="victory.killerHandle"></span>
                    </p>
                </template>

                <ul class="space-y-1 font-mono text-sm">
                    <template x-for="(row, i) in rows" :key="row.userId">
                        <li class="flex items-baseline justify-between gap-3 border-b border-slate-800 py-2 last:border-b-0">
                            <span class="text-amber-200" x-text="`${i + 1}.`"></span>
                            <span class="flex-1 truncate text-slate-100" x-text="row.handle"></span>
                            <span class="text-slate-300" x-text="row.damage.toLocaleString()"></span>
                        </li>
                    </template>
                    <li x-show="rows.length === 0" class="py-3 text-center text-xs text-slate-500">No damage logged yet.</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        window.battlefieldLeaderboardOverlay = function () {
            return {
                open: false,
                rows: [],
                victory: null,
                init() {
                    const tryWire = () => {
                        if (!window.__battlefield?.bus) {
                            setTimeout(tryWire, 50);
                            return;
                        }
                        const bus = window.__battlefield.bus;
                        bus.on('leaderboard-updated', ranked => {
                            this.rows = ranked;
                        });
                        bus.on('show-mvp-overlay', payload => {
                            this.victory = {
                                bossLabel: payload.bossLabel,
                                killerHandle: payload.killerHandle,
                            };
                            this.rows = payload.ranked;
                            this.open = true;
                        });
                        bus.on('boss-spawned', () => {
                            this.victory = null;
                            this.rows = [];
                        });
                    };
                    tryWire();
                },
            };
        };
    </script>

    @script
    <script>
        (() => {
            const mount = document.getElementById('battlefield-mount');
            if (!mount) {
                return;
            }
            if (window.__battlefield?.game) {
                window.__battlefield.game.destroy(true);
                window.__battlefield = null;
            }
            const state = JSON.parse(mount.dataset.battlefieldState);
            window.bootBattlefield(mount, state);
        })();
    </script>
    @endscript
</div>
