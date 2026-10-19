{{-- The custom minion editor, in the Forge drawer's Minions tab. It is built from the fighter editor's pieces
     (.cce sections, the upload form, .versions cards with .vsprite, the in-sheet .confirm-row); no new CSS classes.
     Every change is announced as `custom-minions-changed {minions, draft}` and a result line as `sheet-toast`. --}}
{{-- No Alpine state on this root, for the reason given in custom-character-editor.blade.php: what is pending
     (a confirm row) lives in $confirming. --}}
<div class="cce cme" id="cme-{{ $this->getId() }}">
    @error('action')
        <div class="alert crit" role="alert"><span>{{ $message }}</span></div>
    @enderror

    <section class="cce-sec" aria-labelledby="cme-set-title-{{ $this->getId() }}">
        <div class="cce-head">
            <h3 id="cme-set-title-{{ $this->getId() }}">Your minions</h3>
            <span class="show-state {{ $full ? 'previewing' : '' }}">{{ count($cards) }} of {{ $max }}</span>
        </div>

        @if (count($cards) === 0)
            <p class="cce-note">Your subagents summon the default minions. Add up to {{ $max }} of your own.</p>
        @else
            <ul class="versions" aria-label="Your minions">
                @foreach ($cards as $card)
                    <li class="version" wire:key="minion-{{ $card['id'] }}" @if ($loop->first) tabindex="-1" data-focus="minions-first" @endif aria-label="Minion {{ $card['number'] }}">
                        <span class="vsprite" aria-hidden="true" style="background-image: url('{{ $card['idle']['url'] }}'); --frames: {{ $card['idle']['frames'] }}; --rate: {{ $card['idle']['rate'] }};"></span>
                        <span class="cce-note">Minion {{ $card['number'] }}{{ $card['source'] === 'fighter' ? ' · your fighter' : '' }}</span>
                        @if ($confirming === 'minion-'.$card['id'])
                            <div class="alert crit confirm-row" role="alertdialog" aria-label="Remove minion {{ $card['number'] }}" wire:keydown.escape.stop.prevent="dismissConfirm">
                                <span>Remove minion {{ $card['number'] }}? Your subagents stop summoning it.</span>
                                <button type="button" class="btn" x-init="$el.focus()" wire:click="dismissConfirm">Cancel</button>
                                <button type="button" class="btn blood" wire:click="removeMinion({{ $card['id'] }})" wire:loading.attr="disabled" wire:target="removeMinion">Remove</button>
                            </div>
                        @else
                            <div class="cce-row">
                                <button type="button" class="btn" x-on:click="$dispatch('custom-minion-peek', { spec: @js($card['spec']) })">Preview</button>
                                <button type="button" class="btn" id="cme-remove-open-{{ $card['id'] }}-{{ $this->getId() }}" wire:click="askToConfirm('minion-{{ $card['id'] }}')">Remove</button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($full)
            <p class="cce-note" id="cme-full-{{ $this->getId() }}" role="status">{{ $fullMessage }}</p>
        @endif
    </section>

    <section class="cce-sec" aria-labelledby="cme-add-title-{{ $this->getId() }}">
        <div class="cce-head">
            <h3 id="cme-add-title-{{ $this->getId() }}">Add a minion</h3>
            <a class="panel-link cce-guide" href="{{ route('custom-character') }}" target="_blank" rel="noopener">Need a sheet? Open the prompt guide &#8599;</a>
        </div>

        @if ($draft)
            <div class="cce-filecard" role="status">
                <div class="cce-fileinfo">
                    <span class="cce-file">A built preview is on the stage.</span>
                    <span class="cce-note">It joins your minions only when you add it.</span>
                </div>
                <div class="cce-row">
                    @if ($full)
                        <button type="button" class="btn primary" disabled aria-describedby="cme-full-{{ $this->getId() }}">Add to minions</button>
                    @else
                        <button type="button" class="btn primary" wire:click="confirm('{{ $draft['hash'] }}')" wire:loading.attr="disabled" wire:target="confirm">Add to minions</button>
                    @endif
                    <button type="button" class="btn" wire:click="discard('{{ $draft['hash'] }}')" wire:loading.attr="disabled" wire:target="discard">Discard</button>
                </div>
            </div>
        @endif

        @if ($full)
            <div>
                <button type="button" class="btn primary" disabled data-minion-add="disabled" aria-describedby="cme-full-{{ $this->getId() }}">Add minion</button>
            </div>
        @else
            {{-- Choosing a file (and sending it to the server) is one step; building the preview from it is the next. --}}
            <form
                x-data="{ phase: 'idle', over: false, thumb: null, info: null, baseInfo: null, thumbError: false, progress: 0, busy() { return this.phase === 'uploading' || this.phase === 'building'; }, choose(file) { if (this.thumb) URL.revokeObjectURL(this.thumb); this.thumb = file ? URL.createObjectURL(file) : null; this.baseInfo = file ? file.name + ' · ' + Math.ceil(file.size / 1024) + ' KB' : null; this.info = this.baseInfo; this.thumbError = false; this.phase = file ? 'uploading' : 'idle'; }, drop(event) { this.over = false; if (this.busy() || ! event.dataTransfer.files.length) return; this.$refs.file.files = event.dataTransfer.files; this.$refs.file.dispatchEvent(new Event('change', { bubbles: true })); }, showDimensions(image) { if (this.baseInfo) this.info = this.baseInfo + ' · ' + image.naturalWidth + '×' + image.naturalHeight; }, clearThumb() { if (this.thumb) URL.revokeObjectURL(this.thumb); this.thumb = null; this.baseInfo = null; this.info = null; }, destroy() { this.clearThumb(); } }"
                x-on:livewire-upload-start="phase = 'uploading'; progress = 0"
                x-on:livewire-upload-progress="progress = $event.detail.progress"
                x-on:livewire-upload-finish="phase = 'ready'"
                x-on:livewire-upload-error="phase = 'failed'"
                x-on:custom-minions-changed.window="if ($event.detail?.draft) { phase = 'done'; clearThumb() } else if (phase === 'done') { phase = 'idle' }"
                x-on:submit="phase = 'building'"
                x-effect="window.dispatchEvent(new CustomEvent('custom-forge-phase', { detail: { phase, source: 'minions' } }))"
                wire:submit="build"
                class="cce-form"
                data-minion-add="ready"
            >
                <input type="file" wire:model="sheet" x-ref="file" x-on:change="choose($event.target.files[0] ?? null)" accept="image/png,image/jpeg,image/webp" class="sr-only" tabindex="-1">

                <div class="cce-pick" x-show="phase === 'idle' || phase === 'failed' || phase === 'done'">
                    <div
                        class="cce-drop"
                        x-bind:class="{ over: over }"
                        x-on:dragover.prevent="over = true"
                        x-on:dragleave="over = false"
                        x-on:drop.prevent="drop($event)"
                    >
                        <svg class="cce-drop-icon" viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path d="M12 16V5M7 10l5-5 5 5M5 19h14" fill="none" stroke="currentColor" stroke-width="2" /></svg>
                        <p class="cce-drop-title">Drop your minion's pose sheet here</p>
                        <button type="button" x-on:click="$refs.file.click()" class="btn primary" @if (count($cards) === 0) data-focus="minions-first" @endif>Add minion</button>
                        <ul class="cce-chips" aria-label="File requirements">
                            <li>PNG · JPG · WebP</li>
                            <li>up to 4 MB</li>
                            <li>up to 2048 px</li>
                            <li>on magenta or transparent</li>
                        </ul>
                    </div>
                </div>

                <div class="cce-filecard" x-show="info && (phase === 'uploading' || phase === 'ready' || phase === 'building')" x-cloak>
                    <div class="cce-thumb">
                        <img x-bind:src="thumb" x-on:load="showDimensions($el)" x-on:error="thumbError = true" alt="Selected pose sheet thumbnail">
                    </div>
                    <div class="cce-fileinfo">
                        <span class="cce-file" x-text="info"></span>
                        <span x-show="thumbError" role="status" class="cce-note">This file can't be previewed</span>
                        <div x-show="phase === 'uploading'" role="status">
                            <span class="cce-note">Uploading <span x-text="progress + '%'"></span></span>
                            <div class="upload-bar"><i x-bind:style="{ '--p': progress / 100 }"></i></div>
                        </div>
                        <p x-show="phase === 'ready'" class="cce-note cce-ok" role="status"><svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M3 8.5l3.2 3L13 4.5" fill="none" stroke="currentColor" stroke-width="2" /></svg> Uploaded. Build it to try it on the stage.</p>
                        <div x-show="phase === 'building'" role="status">
                            <span class="cce-note">Building your minion…</span>
                            <div class="build-bar"><i></i></div>
                        </div>
                        <button type="button" class="cce-link" x-show="phase === 'ready'" x-on:click="$refs.file.click()">Choose another file</button>
                    </div>
                    <button type="submit" x-bind:disabled="phase !== 'ready'" class="btn primary cce-build" wire:target="build">
                        <span x-text="phase === 'building' ? 'Building…' : 'Build preview'">Build preview</span>
                    </button>
                </div>

                @error('sheet')
                    <div class="alert crit" role="alert" x-init="phase = 'failed'"><span>{{ $message }}</span></div>
                @enderror
            </form>
        @endif

        @if ($fighterOffer)
            <div class="cce-row">
                @if ($fighterOffer['reason'] === null)
                    <button type="button" class="btn" data-minion-add-fighter="ready" wire:click="addFighter" wire:loading.attr="disabled" wire:target="addFighter">Add my fighter</button>
                @else
                    <button type="button" class="btn" disabled data-minion-add-fighter="disabled" aria-describedby="cme-fighter-reason-{{ $this->getId() }}">Add my fighter</button>
                    <span class="cce-note" id="cme-fighter-reason-{{ $this->getId() }}">{{ $fighterOffer['reason'] }}</span>
                @endif
            </div>
        @endif
    </section>
</div>
