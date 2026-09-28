<div class="relative min-h-screen bg-slate-950 text-white">
    @if ($hookOutdated)
        {{-- Dismissal is namespaced by version, so dismissing hides it until a
             NEWER version ships rather than until the next reload. This page
             gets reloaded constantly; a banner that returns every time gets
             muted and then ignored. localStorage throws outright in some
             contexts, so every access is guarded. --}}
        <div
            x-data="{
                show: false,
                key: 'ts:update-dismissed:{{ $latestHookVersion }}',
                init() { try { this.show = localStorage.getItem(this.key) !== '1'; } catch (e) { this.show = true; } },
                dismiss() { this.show = false; try { localStorage.setItem(this.key, '1'); } catch (e) {} },
            }"
            x-show="show"
            x-cloak
            class="absolute top-0 inset-x-0 z-30 flex flex-wrap items-center justify-center gap-3 bg-amber-500/15 border-b border-amber-500/40 px-4 py-3 text-sm text-amber-200"
        >
            <span>Your hook is out of date &mdash; usage is being recorded with less detail.</span>
            {{-- Only reached by a hook too old to report its own version, so
                 it predates `tok update` too -- a hook that CAN report a
                 version already self-updates on its own and never sets
                 $hookOutdated in the first place. --}}
            <a href="{{ route('update') }}" class="text-amber-100 underline hover:text-white">Re-run the installer</a>
            <button type="button" @click="dismiss()" class="ml-2 text-amber-300/70 hover:text-amber-100" aria-label="Dismiss">&times;</button>
        </div>
    @endif
    <div
        id="battlefield-mount"
        data-battlefield-state="{{ json_encode([
            'boss' => [
                'number' => $boss->number,
                'name' => $boss->name,
                'currentHp' => $boss->current_hp,
                'maxHp' => $boss->max_hp,
            ],
            'currentUserId' => auth()->id(),
            'fighters' => $fighters->map(fn ($f) => [
                'id' => $f->id,
                'handle' => $f->displayHandle(),
                'avatarUrl' => route('avatar', $f),
                'character' => $f->characterForBoss($boss->id),
                'charging' => $this->chargingByUser[$f->id] ?? null,
                'position' => $this->positionsByUser[$f->id] ?? null,
                'agentCount' => $this->agentCountsByUser[$f->id] ?? 0,
            ])->values(),
            'leaderboard' => $this->leaderboardForCurrentBoss(),
            'damageTotals' => $this->damageTotalsForCurrentBoss(),
            'globalDamage' => $this->globalDamage(),
            'sky' => config('token_slayer.sky'),
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

        @auth
            <livewire:fighter-sheet />
        @endauth
    @endunless

    @if (session('error'))
        <div class="absolute left-1/2 top-3 z-20 max-w-[90vw] -translate-x-1/2 rounded-lg border border-red-500/40 bg-red-950/85 px-4 py-2 text-center text-xs font-medium text-red-200 backdrop-blur-sm">
            {{ session('error') }}
        </div>
    @endif

    <div id="bf-hud" class="bf-hud" x-data="battlefieldHud()" x-init="init()" x-cloak>
        <div id="bf-hud-in" class="bf-hud-in">
            @unless (request('embed') === 'ide')
            {{-- In the HUD grid, its own row above Team Damage, so the two can never overlap. --}}
            <nav id="bf-nav" class="bf-nav flex flex-wrap items-center gap-1.5">
                {{-- The Profile pill opens the fighter sheet in place; there is no
                     separate profile page any more. A guest is sent to log in. --}}
                @auth
                    <button type="button" @click="$dispatch('open-fighter-sheet', { tab: 'profile' })" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-white/10 bg-black/50 px-3 py-1.5 text-xs font-medium text-slate-400 backdrop-blur-sm transition-colors hover:border-amber-500/40 hover:text-amber-300">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                    </svg>
                        Profile
                    </button>
                @else
                    <a href="{{ route('slack.login') }}" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-white/10 bg-black/50 px-3 py-1.5 text-xs font-medium text-slate-400 backdrop-blur-sm transition-colors hover:border-amber-500/40 hover:text-amber-300">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                    </svg>
                        Profile
                    </a>
                @endauth
                <a
                    href="{{ route('filament.admin.pages.dashboard') }}"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-black/50 px-3 py-1.5 text-xs font-medium text-slate-400 backdrop-blur-sm transition-colors hover:border-amber-500/40 hover:text-amber-300"
                >
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
                    </svg>
                    Dashboard
                </a>
            </nav>
            @endunless
            <section class="bf-team" aria-label="Team damage">@include('livewire.battlefield.hud-team')</section>
            <section class="bf-plate" aria-label="Boss">@include('livewire.battlefield.hud-plate')</section>
            <section class="bf-board" aria-label="Top damage" :class="{ open: boardOpen }">
                {{-- portrait: the header is the sheet's handle; a real button so keyboards and screen readers can open it --}}
                <button type="button" class="bf-board-handle" @click="toggleBoard()" :aria-expanded="boardOpen.toString()" aria-controls="bf-board-rows">TOP DAMAGE</button>
                @include('livewire.battlefield.hud-board')
            </section>
            <div class="bf-herald" aria-live="polite"><span :class="{on: herald}" x-text="herald?.text" :style="{'--hc': herald?.kind === 'kill' ? '#f87171' : '#fbbf24'}"></span></div>
            <section class="bf-feed" aria-live="polite">@include('livewire.battlefield.hud-feed')</section>
        </div>
    </div>

    {{-- battlefieldHud is a real ES module (resources/js/battlefield/hud/index.js),
         registered on Alpine.data from resources/js/app.js — no inline <script> here. --}}

    @script
    <script>
        (() => {
            const mount = document.getElementById('battlefield-mount');
            if (!mount) {
                return;
            }
            const boot = () => {
                if (window.__battlefield?.game) {
                    window.__battlefield.game.destroy(true);
                    window.__battlefield = null;
                }
                const state = JSON.parse(mount.dataset.battlefieldState);
                window.bootBattlefield(mount, state);
            };
            // bootBattlefield may not be defined yet if Phaser is still loading
            if (window.bootBattlefield) {
                boot();
            } else {
                window.__battlefieldModule?.then(boot) ?? boot();
            }
        })();
    </script>
    @endscript
</div>
