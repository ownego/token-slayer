<div class="cce cce--{{ $variant }}" id="cce-{{ $this->getId() }}">
    @if ($notice)
        <div class="cce-banner cce-banner--ok" role="status">
            <svg class="cce-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.7-9.3a1 1 0 0 0-1.4-1.4L9 10.6 7.7 9.3a1 1 0 0 0-1.4 1.4l2 2a1 1 0 0 0 1.4 0l4-4Z" clip-rule="evenodd"/></svg>
            <span>{{ $notice }}</span>
        </div>
    @endif

    @error('action')
        <p class="cce-banner cce-banner--error" role="alert">{{ $message }}</p>
    @enderror

    @if ($draft)
        <section class="cce-card cce-draft" id="cce-draft-{{ $this->getId() }}" aria-labelledby="cce-draft-title-{{ $this->getId() }}">
            <h3 class="cce-title" id="cce-draft-title-{{ $this->getId() }}">Preview <span class="cce-tag">not live yet</span></h3>
            <p class="cce-dim">This is how your sheet came out. It only goes live when you use it; your current fighter stays until then.</p>
            @include('partials.custom-character-strips', ['strips' => $draft['strips'], 'prefix' => 'draft'])
            <div class="cce-actions">
                <button type="button" class="cce-btn cce-btn--primary" wire:click="confirm('{{ $draft['hash'] }}')" wire:loading.attr="disabled" wire:target="confirm,discard">Use this fighter</button>
                <button type="button" class="cce-btn" wire:click="discard" wire:loading.attr="disabled" wire:target="confirm,discard">Discard</button>
            </div>
        </section>
    @endif

    <section class="cce-card" aria-labelledby="cce-upload-title-{{ $this->getId() }}">
        <h3 class="cce-title" id="cce-upload-title-{{ $this->getId() }}">{{ $character ? 'Replace your sheet' : 'Upload your sheet' }}</h3>
        <p class="cce-dim">PNG, JPEG or WebP, up to 4 MB and 2048 px a side. It needs four rows of poses on a solid magenta or transparent background: idle (2 to 4 poses), walk (4), attack (3) and defeated (3).</p>

        {{-- Choosing a file (and sending it to the server) is its own step; the second button builds a preview from it. --}}
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
                <button type="button" x-on:click="$refs.file.click()" x-bind:disabled="uploading" class="cce-btn">
                    <svg class="cce-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 4a2 2 0 0 1 2-2h4l2 2h4a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4Z"/></svg>
                    <span x-text="name ? 'Choose another file' : 'Choose file'">Choose file</span>
                </button>
                <span class="cce-dim cce-filename" x-text="name ?? 'No file chosen'">No file chosen</span>
            </div>

            <div x-show="uploading" x-cloak class="cce-progress" role="status">
                <div class="cce-row">
                    <svg class="cce-icon cce-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle opacity=".25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path opacity=".75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"/></svg>
                    <span>Uploading <span x-text="progress + '%'"></span></span>
                </div>
                <div class="cce-bar"><div class="cce-bar-fill" x-bind:style="`width: ${progress}%`"></div></div>
            </div>

            <p x-show="ready && ! uploading" x-cloak class="cce-ok">File uploaded. Press the button below to build a preview.</p>

            @error('sheet')
                <p class="cce-banner cce-banner--error" role="alert">{{ $message }}</p>
            @enderror

            <div class="cce-row">
                <button type="submit" x-bind:disabled="! ready || uploading" aria-describedby="cce-hint-{{ $this->getId() }}" class="cce-btn cce-btn--primary" wire:loading.class="cce-busy" wire:target="build">
                    <svg wire:loading wire:target="build" class="cce-icon cce-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle opacity=".25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path opacity=".75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"/></svg>
                    <span wire:loading.remove wire:target="build">Build preview</span>
                    <span wire:loading wire:target="build">Building your fighter…</span>
                </button>
                <span class="cce-dim" id="cce-hint-{{ $this->getId() }}" x-show="! ready && ! uploading">Choose a file first.</span>
            </div>
        </form>
    </section>

    @if ($character)
        <section class="cce-card" aria-labelledby="cce-fighter-title-{{ $this->getId() }}">
            <h3 class="cce-title" id="cce-fighter-title-{{ $this->getId() }}">Your fighter <span class="cce-tag">{{ $character['active'] ? 'equipped' : 'not equipped' }}</span></h3>
            @include('partials.custom-character-strips', ['strips' => $character['strips'], 'prefix' => 'current'])

            <form wire:submit="rename" class="cce-form">
                <label class="cce-label" for="cce-name-{{ $this->getId() }}">Name</label>
                <div class="cce-row">
                    <input type="text" id="cce-name-{{ $this->getId() }}" wire:model="name" maxlength="{{ config('token_slayer.custom_character.name_max') }}" placeholder="{{ config('token_slayer.custom_character.default_name') }}" class="cce-input" autocomplete="off">
                    <button type="submit" class="cce-btn" wire:loading.attr="disabled" wire:target="rename">Save name</button>
                </div>
                @error('name')
                    <p class="cce-banner cce-banner--error" role="alert">{{ $message }}</p>
                @enderror
            </form>

            <div class="cce-actions">
                <button type="button" class="cce-btn cce-btn--danger" wire:click="remove" wire:confirm="Remove your custom fighter and every version of it?" wire:loading.attr="disabled" wire:target="remove">Remove fighter</button>
            </div>
        </section>

        @if (count($character['versions']) > 1)
            <section class="cce-card" aria-labelledby="cce-versions-title-{{ $this->getId() }}">
                <h3 class="cce-title" id="cce-versions-title-{{ $this->getId() }}">Earlier versions</h3>
                <ul class="cce-versions">
                    @foreach ($character['versions'] as $version)
                        <li class="cce-version" wire:key="version-{{ $version['hash'] }}">
                            <div class="cce-thumb">
                                <div class="cce-sprite cce-sprite--small" style="background-image: url('{{ $version['idle']['url'] }}'); --frames: {{ $version['idle']['frames'] }}; --rate: {{ $version['idle']['rate'] }};"></div>
                            </div>
                            <div class="cce-version-info">
                                <span>{{ $version['at']?->diffForHumans() }}</span>
                                @if ($version['current'])
                                    <span class="cce-tag">in use</span>
                                @endif
                            </div>
                            @unless ($version['current'])
                                <div class="cce-actions">
                                    <button type="button" class="cce-btn" wire:click="equipVersion('{{ $version['hash'] }}')" wire:loading.attr="disabled" wire:target="equipVersion,deleteVersion">Equip this one</button>
                                    <button type="button" class="cce-btn cce-btn--danger" wire:click="deleteVersion('{{ $version['hash'] }}')" wire:confirm="Delete this version?" wire:loading.attr="disabled" wire:target="equipVersion,deleteVersion">Delete</button>
                                </div>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endif
</div>
