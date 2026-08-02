{{-- resources/views/partials/setup/cowork-track.blade.php --}}
<div x-show="track === 'cowork'" x-cloak x-data="{ platform: null, plainTokenGenerated: false }">
    {{-- Step 2: platform (macOS/Windows only — Cowork has no Linux build) --}}
    <x-setup.step :n="2">
        <p class="text-xs font-semibold text-orange-600 mb-1">Step 2 / 4</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Choose your platform</h2>
        <div class="grid grid-cols-2 gap-3">
            <button type="button" @click="platform = 'macos'; direction = 1; step = 3" class="cursor-pointer border-2 rounded-lg py-4 text-sm font-semibold transition" :class="platform === 'macos' ? 'border-orange-500 text-orange-600' : 'border-gray-200 text-gray-700 hover:border-gray-300'">macOS</button>
            <button type="button" @click="platform = 'windows'; direction = 1; step = 3" class="cursor-pointer border-2 rounded-lg py-4 text-sm font-semibold transition" :class="platform === 'windows' ? 'border-orange-500 text-orange-600' : 'border-gray-200 text-gray-700 hover:border-gray-300'">Windows</button>
        </div>
        <button type="button" @click="direction = -1; track = null" class="cursor-pointer mt-4 text-sm font-semibold text-gray-500 hover:text-orange-600 transition inline-flex items-center gap-1">← Back</button>
    </x-setup.step>

    {{-- Step 3: python note (no branch — cowork just needs any python3/python) --}}
    <x-setup.step :n="3">
        <p class="text-xs font-semibold text-orange-600 mb-1">Step 3 / 4</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Python requirement</h2>
        <p class="text-sm text-gray-600 mb-4">The Cowork watcher just needs <b>any</b> Python 3 already on your machine — it doesn't require 3.10+ like the CLI track, and most machines already have one.</p>
        <div class="flex items-center justify-between">
            <button type="button" @click="direction = -1; step = 2" class="cursor-pointer text-sm font-semibold text-gray-500 hover:text-orange-600 transition inline-flex items-center gap-1">← Back</button>
            <button type="button" @click="direction = 1; step = 4" class="cursor-pointer bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-gray-700 transition">Continue →</button>
        </div>
    </x-setup.step>

    {{-- Step 4: install + verify + attribution caveat --}}
    <x-setup.step :n="4">
        <p class="text-xs font-semibold text-orange-600 mb-1">Step 4 / 4</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Install</h2>

        <template x-if="!plainTokenGenerated">
            <button type="button" @click="$wire.generateToken().then(() => plainTokenGenerated = true)" class="cursor-pointer bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-lg mb-2 hover:bg-gray-700 transition">Generate token</button>
        </template>
        <p class="text-xs text-amber-700 mb-4">Generating a new token here invalidates the old one everywhere else it's used — including the Claude CLI track on this same machine.</p>

        <div class="bg-gray-900 text-amber-300 rounded-lg p-3 pr-24 relative font-mono text-sm cursor-pointer mb-2" @click="copy('cowork-install', '{{ addslashes($installCowork) }}')">
            {{ $installCowork }}
            <span class="absolute right-2 top-2 bg-gray-800 text-gray-300 text-xs font-semibold px-2 py-1 rounded" x-text="copied === 'cowork-install' ? 'Copied' : 'Copy'"></span>
        </div>
        <p x-show="platform === 'windows'" x-cloak class="text-xs text-amber-700 mb-4">Windows: automatic watcher scheduling isn't supported yet — you'll need to re-run the command above on your own schedule.</p>

        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800 mb-4">
            Cowork's token usage is real (Cowork runs actual Claude Code in a VM, counting against that account's 5h/7d quota) — but the watcher <b>can't attribute it to a specific account</b>, so it always shows as personal usage on <code>/profile</code> no matter which account you use inside Cowork.
        </div>

        <button type="button" @click="direction = -1; step = 3" class="cursor-pointer text-sm font-semibold text-gray-500 hover:text-orange-600 transition inline-flex items-center gap-1">← Back</button>
    </x-setup.step>
</div>
