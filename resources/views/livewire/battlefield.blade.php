<div
    x-data="{
        hp: {{ $boss->current_hp }},
        max: {{ $boss->max_hp }},
        isShaking: false,
        isFlashing: false,
        projectiles: [],
        nextProjectileId: 1,
        spawnProjectile(detail) {
            const fighterEl = document.querySelector(`[data-fighter-id='${detail.user_id}']`);
            const bossEl = document.querySelector('[data-boss-target]');
            if (! bossEl) { return; }
            const bossRect = bossEl.getBoundingClientRect();
            const toX = bossRect.left + bossRect.width / 2;
            const toY = bossRect.top + bossRect.height / 2;
            let fromX = toX, fromY = toY;
            if (fighterEl) {
                const r = fighterEl.getBoundingClientRect();
                fromX = r.left + r.width / 2;
                fromY = r.top + r.height / 2;
            }
            const id = this.nextProjectileId++;
            this.projectiles.push({ id, fromX, fromY, toX, toY, inFlight: false });
            requestAnimationFrame(() => {
                const p = this.projectiles.find(p => p.id === id);
                if (p) { p.inFlight = true; }
            });
        },
    }"
    x-init="
        const applyHit = (e) => {
            hp = e.boss_hp_after;
            isShaking = true;
            setTimeout(() => isShaking = false, 200);
        };

        window.addEventListener('battlefield:hit', (ev) => {
            spawnProjectile(ev.detail);
            applyHit(ev.detail);
        });

        if (window.Echo) {
            window.Echo.channel('battlefield')
                .listen('.HitDealt', e => window.dispatchEvent(new CustomEvent('battlefield:hit', { detail: e })))
                .listen('.BossSpawned', e => { hp = e.max_hp; max = e.max_hp; })
                .listen('.BossKilled', () => {
                    isFlashing = true;
                    setTimeout(() => isFlashing = false, 600);
                });
        }
    "
    class="relative min-h-screen bg-slate-950 text-white"
>
    <div class="absolute inset-0 flex flex-col items-center justify-center gap-4" data-boss-target>
        <h2 class="text-3xl font-bold" :class="{ 'shake': isShaking }">Boss #{{ $boss->number }}</h2>
        <div class="w-96 h-4 bg-slate-700 rounded">
            <div class="h-4 bg-red-500 rounded" :style="`width: ${(hp / max) * 100}%`" style="width: {{ ($boss->current_hp / $boss->max_hp) * 100 }}%"></div>
        </div>
        <span class="text-sm text-gray-300" x-text="`${hp.toLocaleString()} / ${max.toLocaleString()}`">{{ number_format($boss->current_hp) }} / {{ number_format($boss->max_hp) }}</span>
    </div>

    <div class="absolute inset-x-0 bottom-8 flex justify-center gap-4 flex-wrap">
        @foreach ($fighters as $f)
            <div class="text-center" wire:key="fighter-{{ $f->id }}" data-fighter-id="{{ $f->id }}">
                <img src="{{ $f->avatar_url }}" class="w-12 h-12 rounded-full ring-2 ring-amber-400">
                <p class="text-xs mt-1">{{ $f->slack_handle }}</p>
            </div>
        @endforeach
    </div>

    <div x-show="isFlashing" class="absolute inset-0 bg-white pointer-events-none flash" style="display: none;"></div>

    <template x-for="p in projectiles" :key="p.id">
        <span
            data-projectile
            class="projectile"
            :style="`left: ${p.fromX}px; top: ${p.fromY}px; transform: translate(${p.inFlight ? (p.toX - p.fromX) : 0}px, ${p.inFlight ? (p.toY - p.fromY) : 0}px);`"
        >💥</span>
    </template>
</div>
