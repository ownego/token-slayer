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
                'handle' => $f->slack_handle,
                'avatarUrl' => route('avatar', $f),
            ])->values(),
        ]) }}"
        class="absolute inset-0"
    ></div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const mount = document.getElementById('battlefield-mount');
            const state = JSON.parse(mount.dataset.battlefieldState);
            window.bootBattlefield(mount, state);
        });
    </script>
</div>
