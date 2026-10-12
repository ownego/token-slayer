<div>
    @include('partials.account-nav', ['active' => 'character'])

    {{-- Each strip is a row of 100 px frames; stepping the background across them plays it. --}}
    <style>
        .ccu-sprite {
            width: 100px;
            height: 100px;
            background-size: auto 100px;
            background-repeat: no-repeat;
            image-rendering: pixelated;
            animation: ccu-play calc(var(--frames) / var(--rate) * 1s) steps(var(--frames)) infinite;
        }
        @keyframes ccu-play {
            from { background-position-x: 0; }
            to { background-position-x: calc(var(--frames) * -100px); }
        }
        @media (prefers-reduced-motion: reduce) {
            .ccu-sprite { animation: none; }
        }
    </style>

    <div class="max-w-3xl mx-auto p-8 space-y-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 mb-1">Your own fighter</h1>
            <p class="text-sm text-gray-500">Make a character sheet with an image AI, upload it, and it becomes your fighter. It is equipped as soon as it is built; switch between it and the roster in your profile, under Change character.</p>
        </div>

        @if ($notice)
            <div class="flex items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800" role="status">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.7-9.3a1 1 0 0 0-1.4-1.4L9 10.6 7.7 9.3a1 1 0 0 0-1.4 1.4l2 2a1 1 0 0 0 1.4 0l4-4Z" clip-rule="evenodd"/></svg>
                <span>{{ $notice }}</span>
            </div>
        @endif

        @if ($strips)
            <section class="bg-white border border-gray-200 rounded-xl p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-bold text-gray-900">Your fighter</h2>
                    <button type="button" wire:click="remove" wire:confirm="Remove your custom fighter?" class="text-sm text-gray-500 hover:text-red-600">Remove</button>
                </div>
                <div class="flex flex-wrap gap-6 bg-gray-900 rounded-lg p-4">
                    @foreach ($strips as $strip)
                        <div class="text-center" wire:key="strip-{{ $strip['name'] }}">
                            <div class="ccu-sprite" style="background-image: url('{{ $strip['url'] }}'); --frames: {{ $strip['frames'] }}; --rate: {{ $strip['rate'] }};"></div>
                            <div class="text-xs text-gray-400 mt-1">{{ $strip['name'] }}</div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="bg-white border border-gray-200 rounded-xl p-5 space-y-4" wire:ignore x-data="characterPrompt(@js($types), @js($prompts), @js($thumbs))">
            <h2 class="font-bold text-gray-900">1. Ask an image AI for a pose sheet</h2>
            <p class="text-sm text-gray-600">Pick a costume by character type below, then copy its prompt and paste it into ChatGPT (or another image AI) together with a picture of your custom character to make your pose sheet. The prompt already spells out that type's costume; write into a line under COSTUME ITEMS only to change that item, and leave it empty to keep the type's default. If one AI refuses your character, try another.</p>

            <div class="grid grid-cols-3 gap-2 sm:grid-cols-5 md:grid-cols-9" role="group" aria-label="Character type">
                <template x-for="(label, key) in types" :key="key">
                    <button type="button" x-on:click="choose(key)" x-bind:aria-pressed="type === key"
                        class="flex flex-col items-center gap-1 rounded-lg border px-1 pb-1.5 pt-2 text-xs font-medium"
                        x-bind:class="type === key ? 'border-orange-500 bg-orange-50 text-orange-700 ring-2 ring-orange-300' : 'border-gray-200 bg-white text-gray-700 hover:border-orange-400 hover:text-orange-600'">
                        <img x-bind:src="thumbs + '/' + key + '.png'" alt="" width="64" height="64" class="h-16 w-16" loading="lazy">
                        <span x-text="label"></span>
                    </button>
                </template>
            </div>

            <p class="text-sm text-gray-500" x-show="! type">Choose a character type to get its prompt.</p>

            <div x-show="type" x-cloak>
                <textarea rows="22" class="w-full text-xs font-mono border border-gray-200 rounded-lg p-3 bg-gray-50" x-model="text" aria-label="Prompt for your image AI"></textarea>
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <button type="button" class="text-sm border border-gray-200 rounded-lg px-3 py-1 text-gray-700 hover:border-orange-400 hover:text-orange-600"
                        x-on:click="copy()" x-text="copied ? 'Copied' : 'Copy prompt'">Copy prompt</button>
                    <span class="text-xs text-gray-500">Switching type keeps the costume items you typed.</span>
                </div>
            </div>
        </section>

        <section class="bg-white border border-gray-200 rounded-xl p-5 space-y-4">
            <h2 class="font-bold text-gray-900">2. Upload the sheet</h2>
            <p class="text-sm text-gray-600">PNG, JPEG or WebP, up to 4 MB. It needs four rows of poses on a solid magenta or transparent background: idle (2 to 4 poses), walk (4), attack (3) and defeated (3).</p>

            {{-- Choosing a file (and sending it to the server) is its own step; the second button builds the fighter from it. --}}
            <form
                x-data="{ name: null, uploading: false, progress: 0, ready: false }"
                x-on:livewire-upload-start="uploading = true; ready = false; progress = 0"
                x-on:livewire-upload-progress="progress = $event.detail.progress"
                x-on:livewire-upload-finish="uploading = false; ready = true"
                x-on:livewire-upload-error="uploading = false; ready = false"
                x-init="$watch('$wire.sheet', (value) => { if (! value && ! uploading) { name = null; ready = false } })"
                wire:submit="build"
                class="space-y-3"
            >
                <input type="file" wire:model="sheet" x-ref="file" x-on:change="name = $event.target.files[0]?.name ?? null" accept="image/png,image/jpeg,image/webp" class="sr-only" tabindex="-1">

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" x-on:click="$refs.file.click()" x-bind:disabled="uploading" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:border-orange-400 hover:text-orange-600 disabled:opacity-50">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 4a2 2 0 0 1 2-2h4l2 2h4a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4Z"/></svg>
                        <span x-text="name ? 'Choose another file' : 'Choose file'">Choose file</span>
                    </button>
                    <span class="text-sm text-gray-500 truncate max-w-xs" x-text="name ?? 'No file chosen'">No file chosen</span>
                </div>

                <div x-show="uploading" x-cloak class="space-y-1" role="status">
                    <div class="flex items-center gap-2 text-sm text-gray-600">
                        <svg class="h-4 w-4 animate-spin text-orange-500" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"/></svg>
                        <span>Uploading <span x-text="progress + '%'"></span></span>
                    </div>
                    <div class="h-1.5 w-full max-w-xs overflow-hidden rounded-full bg-gray-200">
                        <div class="h-full bg-orange-500 transition-all" x-bind:style="`width: ${progress}%`"></div>
                    </div>
                </div>

                <p x-show="ready && !uploading" x-cloak class="text-sm text-green-700">File uploaded. Press the button below to build your fighter.</p>

                @error('sheet')
                    <p class="text-sm text-red-600" role="alert">{{ $message }}</p>
                @enderror

                <button type="submit" x-bind:disabled="!ready || uploading" class="inline-flex items-center gap-2 rounded-lg bg-orange-500 px-4 py-2 text-sm font-medium text-white hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-50" wire:loading.class="pointer-events-none opacity-70" wire:target="build">
                    <svg wire:loading wire:target="build" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"/></svg>
                    <span wire:loading.remove wire:target="build">Build my fighter</span>
                    <span wire:loading wire:target="build">Building your fighter…</span>
                </button>
            </form>
        </section>
    </div>
</div>
