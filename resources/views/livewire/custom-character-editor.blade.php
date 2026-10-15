{{-- The custom fighter editor, in the fighter sheet's Character tab. It is built from the sheet's own pieces
     (.btn, .alert, .toast, .show-stage, .hotbar/.move, .show-state); the few new classes are in
     fighter-sheet.css and listed in .ai/design/components.md. Results are announced as a `sheet-toast`. --}}
{{-- No Alpine state on this root: a lazily mounted component is morphed into its placeholder, and bindings made on
     that first render do not follow later changes. What is pending (a confirm row) lives in $confirming instead. --}}
<div class="cce" id="cce-{{ $this->getId() }}">
    @error('action')
        <div class="alert crit" role="alert"><span>{{ $message }}</span></div>
    @enderror

    <section class="cce-sec cce-make" aria-labelledby="cce-upload-title-{{ $this->getId() }}">
        <div class="cce-head">
            <h3 id="cce-upload-title-{{ $this->getId() }}">{{ $character ? 'Make a new version' : 'Make your own fighter' }}</h3>
            <a class="panel-link" href="{{ route('custom-character') }}" target="_blank" rel="noopener">Prompt guide &#8599;</a>
        </div>

        {{-- Choosing a file (and sending it to the server) is one step; building the preview from it is the next. --}}
        <form
            x-data="{ phase: 'idle', over: false, thumb: null, info: null, baseInfo: null, thumbError: false, progress: 0, get step() { return this.phase === 'done' ? 3 : (this.phase === 'idle' || this.phase === 'failed') ? 1 : 2; }, busy() { return this.phase === 'uploading' || this.phase === 'building'; }, choose(file) { if (this.thumb) URL.revokeObjectURL(this.thumb); this.thumb = file ? URL.createObjectURL(file) : null; this.baseInfo = file ? file.name + ' · ' + Math.ceil(file.size / 1024) + ' KB' : null; this.info = this.baseInfo; this.thumbError = false; this.phase = file ? 'uploading' : 'idle'; }, drop(event) { this.over = false; if (this.busy() || ! event.dataTransfer.files.length) return; this.$refs.file.files = event.dataTransfer.files; this.$refs.file.dispatchEvent(new Event('change', { bubbles: true })); }, showDimensions(image) { if (this.baseInfo) this.info = this.baseInfo + ' · ' + image.naturalWidth + '×' + image.naturalHeight; }, clearThumb() { if (this.thumb) URL.revokeObjectURL(this.thumb); this.thumb = null; this.baseInfo = null; this.info = null; }, destroy() { this.clearThumb(); } }"
            x-on:livewire-upload-start="phase = 'uploading'; progress = 0"
            x-on:livewire-upload-progress="progress = $event.detail.progress"
            x-on:livewire-upload-finish="phase = 'ready'"
            x-on:livewire-upload-error="phase = 'failed'"
            x-on:custom-character-changed.window="if ($event.detail?.draft) { phase = 'done'; clearThumb() }"
            x-on:submit="phase = 'building'"
            wire:submit="build"
            class="cce-form"
        >
            <ol class="cce-steps" aria-label="How it works">
                <li x-bind:class="{ now: step === 1, past: step > 1 }"><b>1</b><span>Make a sheet</span></li>
                <li x-bind:class="{ now: step === 2, past: step > 2 }"><b>2</b><span>Upload and build</span></li>
                <li x-bind:class="{ now: step === 3 }"><b>3</b><span>Try it, then use it</span></li>
            </ol>

            @if (! $character && ! $draft)
                @include('partials.custom-character-sheet-diagram')
            @endif

            <input type="file" wire:model="sheet" x-ref="file" x-on:change="choose($event.target.files[0] ?? null)" accept="image/png,image/jpeg,image/webp" class="sr-only" tabindex="-1">

            <div
                class="cce-drop"
                x-bind:class="{ over: over }"
                x-show="phase === 'idle' || phase === 'failed' || phase === 'done'"
                x-on:dragover.prevent="over = true"
                x-on:dragleave="over = false"
                x-on:drop.prevent="drop($event)"
            >
                <svg class="cce-drop-icon" viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path d="M12 16V5M7 10l5-5 5 5M5 19h14" fill="none" stroke="currentColor" stroke-width="2" /></svg>
                <p class="cce-drop-title">Drop your pose sheet here</p>
                <button type="button" x-on:click="$refs.file.click()" class="btn primary" data-focus="upload">Choose pose sheet</button>
                <ul class="cce-chips" aria-label="File requirements">
                    <li>PNG · JPG · WebP</li>
                    <li>up to 4 MB</li>
                    <li>up to 2048 px</li>
                    <li>on magenta or transparent</li>
                </ul>
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
                        <span class="cce-note">Building your fighter…</span>
                        <div class="build-bar"><i></i></div>
                    </div>
                    <button type="button" class="cce-link" x-show="phase === 'ready'" x-on:click="$refs.file.click()" data-focus="upload-again">Choose another file</button>
                </div>
            </div>

            @error('sheet')
                <div class="alert crit" role="alert" x-init="phase = 'failed'"><span>{{ $message }}</span></div>
            @enderror

            <div class="cce-row cce-build-row" x-show="phase === 'ready' || phase === 'building'" x-cloak>
                <button type="submit" x-bind:disabled="phase !== 'ready'" class="btn primary cce-build" wire:target="build">
                    <span x-text="phase === 'building' ? 'Building…' : 'Build preview'">Build preview</span>
                </button>
            </div>
        </form>
    </section>

    @if ($character)
        <section class="cce-sec" aria-labelledby="cce-fighter-title-{{ $this->getId() }}">
            <div class="cce-head">
                <h3 id="cce-fighter-title-{{ $this->getId() }}">Your fighter</h3>
                <span class="show-state {{ $character['active'] ? '' : 'previewing' }}">{{ $character['active'] ? 'Equipped' : 'Not equipped' }}</span>
            </div>

            <form wire:submit="rename" class="cce-form">
                <div class="field">
                    <label for="cce-name-{{ $this->getId() }}">Name</label>
                    <div class="cce-row">
                        <input type="text" id="cce-name-{{ $this->getId() }}" data-focus="name" wire:model="name" maxlength="{{ config('token_slayer.custom_character.name_max') }}" placeholder="{{ config('token_slayer.custom_character.default_name') }}" autocomplete="off" aria-describedby="cce-count-{{ $this->getId() }}">
                        <button type="submit" class="btn" wire:loading.attr="disabled" wire:target="rename">Save name</button>
                    </div>
                    <span class="field-count" id="cce-count-{{ $this->getId() }}"><span x-text="$wire.name.length">0</span> / {{ config('token_slayer.custom_character.name_max') }}</span>
                </div>
                @error('name')
                    <div class="alert crit" role="alert"><span>{{ $message }}</span></div>
                @enderror
            </form>

            <div>
                @if ($confirming === 'remove')
                    <div class="alert crit confirm-row" role="alertdialog" aria-label="Remove {{ $character['name'] }}" wire:keydown.escape.stop.prevent="dismissConfirm">
                        <span>Remove <b>{{ $character['name'] }}</b>{{ count($character['versions']) > 1 ? ' and its '.count($character['versions']).' versions' : '' }}? You go back to your roster fighter.</span>
                        <button type="button" class="btn" x-init="$el.focus()" wire:click="dismissConfirm">Cancel</button>
                        <button type="button" class="btn blood" wire:click="remove" wire:loading.attr="disabled" wire:target="remove">Remove</button>
                    </div>
                @else
                    <button type="button" class="cce-link danger" id="cce-remove-open-{{ $this->getId() }}" wire:click="askToConfirm('remove')">Remove fighter</button>
                @endif
            </div>
        </section>

        @if (count($character['versions']) > 1)
            <section class="cce-sec" aria-labelledby="cce-versions-title-{{ $this->getId() }}">
                <h3 id="cce-versions-title-{{ $this->getId() }}">Earlier versions</h3>
                <ul class="versions">
                    @foreach ($character['versions'] as $version)
                        <li class="version {{ $version['current'] ? 'current' : '' }}" wire:key="version-{{ $version['hash'] }}">
                            <span class="vsprite" aria-hidden="true" style="background-image: url('{{ $version['idle']['url'] }}'); --frames: {{ $version['idle']['frames'] }}; --rate: {{ $version['idle']['rate'] }};"></span>
                            <span class="cce-note">Version {{ $version['number'] }} · <time title="{{ $version['at']?->toDateTimeString() }}">{{ $version['at']?->diffForHumans() }}</time></span>
                            @if ($version['current'])
                                <span class="show-state">In use</span>
                            @else
                                @if ($confirming === 'version-'.$version['hash'])
                                    <div class="alert crit confirm-row" role="alertdialog" aria-label="Delete this version" wire:keydown.escape.stop.prevent="dismissConfirm">
                                        <span>Delete this version?</span>
                                        <button type="button" class="btn" x-init="$el.focus()" wire:click="dismissConfirm">Cancel</button>
                                        <button type="button" class="btn blood" wire:click="deleteVersion('{{ $version['hash'] }}')" wire:loading.attr="disabled" wire:target="equipVersion,deleteVersion">Delete</button>
                                    </div>
                                @else
                                    <div class="cce-row">
                                        <button type="button" class="btn" x-on:click="window.dispatchEvent(new CustomEvent('custom-version-peek', { detail: { hash: @js($version['hash']), spec: @js($version['spec']), number: @js($version['number']) } }))">Preview</button>
                                        <button type="button" class="btn" wire:click="equipVersion('{{ $version['hash'] }}')" wire:loading.attr="disabled" wire:target="equipVersion,deleteVersion">Equip version {{ $version['number'] }}</button>
                                        <button type="button" class="btn" id="cce-version-open-{{ $version['hash'] }}-{{ $this->getId() }}" wire:click="askToConfirm('version-{{ $version['hash'] }}')">Delete version</button>
                                    </div>
                                @endif
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endif
</div>
