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
            <p class="text-sm text-gray-500">Make a character sheet with an image AI, upload it, and it becomes your fighter.</p>
        </div>

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

        <section class="bg-white border border-gray-200 rounded-xl p-5 space-y-4">
            <h2 class="font-bold text-gray-900">1. Ask an image AI for a pose sheet</h2>
            <p class="text-sm text-gray-600">Give ChatGPT (or another image AI) a picture of your character together with this prompt. If one refuses your character, try another.</p>
            <div x-data="{ copied: false }">
                <textarea readonly rows="10" class="w-full text-xs font-mono border border-gray-200 rounded-lg p-3 bg-gray-50" x-ref="prompt">{{ $prompt }}</textarea>
                <button type="button" class="mt-2 text-sm border border-gray-200 rounded-lg px-3 py-1 text-gray-700 hover:border-orange-400 hover:text-orange-600"
                    @click="navigator.clipboard && navigator.clipboard.writeText($refs.prompt.value).catch(() => {}); copied = true; setTimeout(() => copied = false, 1300)"
                    x-text="copied ? 'Copied' : 'Copy prompt'">Copy prompt</button>
            </div>
        </section>

        <section class="bg-white border border-gray-200 rounded-xl p-5 space-y-4">
            <h2 class="font-bold text-gray-900">2. Upload the sheet</h2>
            <p class="text-sm text-gray-600">PNG, JPEG or WebP, up to 4 MB. It needs four rows of poses on a solid magenta or transparent background: idle (2 to 4 poses), walk (4), attack (3) and defeated (3).</p>

            <form wire:submit="upload" class="space-y-3">
                <input type="file" wire:model="sheet" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm text-gray-700">

                @error('sheet')
                    <p class="text-sm text-red-600" role="alert">{{ $message }}</p>
                @enderror

                <button type="submit" class="inline-flex items-center rounded-lg bg-orange-500 px-4 py-2 text-sm font-medium text-white hover:bg-orange-600 disabled:opacity-50" wire:loading.attr="disabled" wire:target="sheet,upload">
                    <span wire:loading.remove wire:target="upload">Upload</span>
                    <span wire:loading wire:target="upload">Building your fighter…</span>
                </button>
            </form>
        </section>
    </div>
</div>
