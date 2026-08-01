<div
    x-data="characterSelectModal(@js($this->characters()), @js($equipped), @entangle('equippedKey'))"
    @open-character-select.window="open()"
>
    <div
        x-show="isOpen"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click.self="isOpen = false"
        class="fixed inset-0 z-30 flex items-center justify-center bg-black/40 backdrop-blur-sm"
    >
        <div class="w-full max-w-xs rounded-2xl border border-white/10 bg-slate-950/95 p-5 text-center shadow-2xl">
            <h2 class="mb-4 text-sm font-semibold tracking-wide text-white">Choose your character</h2>

            <div class="mx-auto mb-3 flex h-28 w-28 items-center justify-center rounded-xl bg-black/40">
                <canvas x-ref="preview" width="112" height="112"></canvas>
            </div>

            <p class="mb-4 text-xs font-medium uppercase tracking-wide text-amber-300" x-text="currentKey()"></p>

            <div class="mb-4 flex items-center justify-center gap-4">
                <button type="button" @click="prev()" class="rounded-full border border-white/10 bg-white/5 px-3 py-2 text-slate-300 hover:text-amber-300">‹</button>
                <button type="button" @click="next()" class="rounded-full border border-white/10 bg-white/5 px-3 py-2 text-slate-300 hover:text-amber-300">›</button>
            </div>

            <button
                type="button"
                @click="equip()"
                class="w-full rounded-lg bg-amber-500/90 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-amber-400"
                x-text="currentKey() === equippedKey ? 'Equipped' : 'Equip'"
                :disabled="currentKey() === equippedKey"
            ></button>
        </div>
    </div>
</div>

<script>
    window.characterSelectModal = function (characters, startingCharacter, equippedKey) {
        return {
            characters,
            startingCharacter,
            equippedKey,
            isOpen: false,
            index: 0,
            init() {
                this.index = Math.max(0, this.characters.indexOf(this.startingCharacter));
                this.$nextTick(() => this.redraw());
            },
            open() {
                this.index = Math.max(0, this.characters.indexOf(this.startingCharacter));
                this.isOpen = true;
                this.$nextTick(() => this.redraw());
            },
            currentKey() {
                return this.characters[this.index];
            },
            prev() {
                this.index = (this.index - 1 + this.characters.length) % this.characters.length;
                this.redraw();
            },
            next() {
                this.index = (this.index + 1) % this.characters.length;
                this.redraw();
            },
            redraw() {
                const bf = window.__battlefield;
                if (!bf?.game) {
                    setTimeout(() => this.redraw(), 50);
                    return;
                }
                bf.drawFighterPreview(bf.game, this.$refs.preview, this.currentKey());
            },
            equip() {
                if (this.currentKey() === this.equippedKey) {
                    return;
                }
                this.startingCharacter = this.currentKey();
                this.$wire.equip(this.currentKey());
                this.isOpen = false;
            },
        };
    };
</script>
