{{-- The custom fighter editor, in the fighter sheet's Character tab. It is built from the sheet's own pieces
     (.btn, .alert, .toast, .show-stage, .hotbar/.move, .show-state); the few new classes are in
     fighter-sheet.css and listed in .ai/design/components.md. Results are announced as a `sheet-toast`. --}}
{{-- No Alpine state on this root: a lazily mounted component is morphed into its placeholder, and bindings made on
     that first render do not follow later changes. What is pending (a confirm row) lives in $confirming instead. --}}
<div class="cce" id="cce-{{ $this->getId() }}">
    @error('action')
        <div class="alert crit" role="alert"><span>{{ $message }}</span></div>
    @enderror

    @if ($draft)
        <section
            class="cce-sec cce-draft"
            id="cce-draft-{{ $this->getId() }}"
            aria-labelledby="cce-draft-title-{{ $this->getId() }}"
            wire:key="draft-{{ $draft['hash'] }}"
            x-data="customDraftPreview(@js($draft['spec']))"
        >
            <div class="cce-head">
                <h3 id="cce-draft-title-{{ $this->getId() }}">{{ $draft['spec']['name'] }}</h3>
                <span class="show-state previewing">Previewing</span>
            </div>
            <div class="show-stage cce-stage" aria-hidden="true">
                <span class="show-sprite" x-ref="sprite"></span>
            </div>
            <div class="hotbar" role="group" aria-label="Poses">
                <template x-for="(move, i) in moves" :key="move.id">
                    <button type="button" class="move" :aria-pressed="active === move.id ? 'true' : 'false'" :title="move.label" @click="select(move)">
                        <span class="key" x-text="i + 1"></span>
                        <div class="thumb"><span x-init="thumb($el, move)"></span></div>
                        <span class="label" x-text="move.label"></span>
                    </button>
                </template>
            </div>
            <div class="cce-row">
                <button type="button" class="btn" @click="toggle()" x-text="playing ? 'Pause' : 'Play'">Pause</button>
                <button type="button" class="btn" @click="step(-1)" aria-label="Previous frame">&larr; Frame</button>
                <button type="button" class="btn" @click="step(1)" aria-label="Next frame">Frame &rarr;</button>
                <span class="cce-note" x-text="'Frame ' + (frame + 1) + ' · ' + (playing ? 'playing' : 'paused')"></span>
            </div>
            <div class="equip-row">
                <span class="equip-note">Not live yet. Your fighter stays as it is until you use this one.</span>
                <button type="button" class="btn" wire:click="discard" wire:loading.attr="disabled" wire:target="confirm,discard">Discard preview</button>
                <button type="button" class="btn primary" wire:click="confirm('{{ $draft['hash'] }}')" wire:loading.attr="disabled" wire:target="confirm,discard">Use this fighter</button>
            </div>
        </section>
    @endif

    <section class="cce-sec" aria-labelledby="cce-upload-title-{{ $this->getId() }}">
        <h3 id="cce-upload-title-{{ $this->getId() }}">{{ $character ? 'Replace the sheet' : 'Upload a sheet' }}</h3>
        <p class="cce-note">PNG, JPEG or WebP, up to 4 MB and 2048 px a side. Four rows of poses on solid magenta or a transparent background: idle (2 to 4), walk (4), attack (3) and death (3).</p>

        {{-- Choosing a file (and sending it to the server) is one step; building the preview from it is the next. --}}
        <form
            x-data="{ name: null, uploading: false, progress: 0, ready: false }"
            x-on:livewire-upload-start="uploading = true; ready = false; progress = 0"
            x-on:livewire-upload-progress="progress = $event.detail.progress"
            x-on:livewire-upload-finish="uploading = false; ready = true"
            x-on:livewire-upload-error="uploading = false; ready = false"
            x-init="$watch('$wire.sheet', (value) => { if (! value && ! uploading) { name = null; ready = false } })"
            wire:submit="build"
            class="cce-form"
        >
            <input type="file" wire:model="sheet" x-ref="file" x-on:change="name = $event.target.files[0]?.name ?? null" accept="image/png,image/jpeg,image/webp" class="sr-only" tabindex="-1">

            <div class="cce-row">
                <button type="button" x-on:click="$refs.file.click()" x-bind:disabled="uploading" class="btn">
                    <span x-text="name ? 'Choose another file' : 'Choose file'">Choose file</span>
                </button>
                <span class="cce-file" x-text="name ?? 'No file chosen'">No file chosen</span>
            </div>

            <div x-show="uploading" x-cloak role="status">
                <span class="cce-note">Uploading <span x-text="progress + '%'"></span></span>
                <div class="upload-bar"><i x-bind:style="{ '--p': progress / 100 }"></i></div>
            </div>

            <p x-show="ready && ! uploading" x-cloak class="cce-note cce-ok" role="status">File uploaded.</p>

            @error('sheet')
                <div class="alert crit" role="alert"><span>{{ $message }}</span></div>
            @enderror

            <div class="cce-row">
                <button type="submit" x-bind:disabled="! ready || uploading" aria-describedby="cce-hint-{{ $this->getId() }}" class="btn primary" wire:target="build">
                    <span wire:loading.remove wire:target="build">Build preview</span>
                    <span wire:loading wire:target="build">Building your fighter…</span>
                </button>
                <span class="cce-note" id="cce-hint-{{ $this->getId() }}" x-show="! ready && ! uploading">Choose a file first.</span>
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
                        <input type="text" id="cce-name-{{ $this->getId() }}" wire:model="name" maxlength="{{ config('token_slayer.custom_character.name_max') }}" placeholder="{{ config('token_slayer.custom_character.default_name') }}" autocomplete="off" aria-describedby="cce-count-{{ $this->getId() }}">
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
                        <button type="button" class="btn blood" wire:click="remove" wire:loading.attr="disabled" wire:target="remove">Remove</button>
                        <button type="button" class="btn" x-init="$el.focus()" wire:click="dismissConfirm">Cancel</button>
                    </div>
                @else
                    <button type="button" class="btn" id="cce-remove-open-{{ $this->getId() }}" wire:click="askToConfirm('remove')">Remove fighter</button>
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
                            <span class="cce-note">{{ $version['at']?->diffForHumans() }}</span>
                            @if ($version['current'])
                                <span class="show-state">In use</span>
                            @else
                                @if ($confirming === 'version-'.$version['hash'])
                                    <div class="alert crit confirm-row" role="alertdialog" aria-label="Delete this version" wire:keydown.escape.stop.prevent="dismissConfirm">
                                        <span>Delete this version?</span>
                                        <button type="button" class="btn blood" wire:click="deleteVersion('{{ $version['hash'] }}')" wire:loading.attr="disabled" wire:target="equipVersion,deleteVersion">Delete</button>
                                        <button type="button" class="btn" x-init="$el.focus()" wire:click="dismissConfirm">Cancel</button>
                                    </div>
                                @else
                                    <div class="cce-row">
                                        <button type="button" class="btn" wire:click="equipVersion('{{ $version['hash'] }}')" wire:loading.attr="disabled" wire:target="equipVersion,deleteVersion">Equip version</button>
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
