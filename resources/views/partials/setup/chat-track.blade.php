{{-- resources/views/partials/setup/chat-track.blade.php --}}
<div x-show="track === 'chat'" x-cloak>
    {{-- Step 2: install steps (no platform branch — browser-based) --}}
    <x-setup.step :n="2">
        <p class="text-xs font-semibold text-orange-600 mb-1">Step 2 / 3</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Install</h2>
        <ol class="text-sm text-gray-700 list-decimal ml-5 space-y-2">
            <li>Install <a href="https://chromewebstore.google.com/detail/tampermonkey/dhdgffkkebhmkfjojejmpbldmpobfkfo" target="_blank" rel="noopener" class="text-orange-600 underline hover:text-orange-700">Tampermonkey</a> (or Violentmonkey) in your browser.</li>
            <li><span class="font-medium">Chrome 138+:</span> open <code>chrome://extensions</code> → Tampermonkey → <em>Details</em> → enable <span class="font-medium">Allow user scripts</span>. Skip this and the tracker installs but never runs.</li>
            <li><a href="{{ $userscriptUrl }}" class="text-orange-600 underline hover:text-orange-700">Install the tracker userscript</a> — your userscript manager will ask you to confirm.</li>
        </ol>
        <div class="flex items-center justify-between mt-4">
            <button type="button" @click="direction = -1; track = null" class="cursor-pointer text-sm font-semibold text-gray-500 hover:text-orange-600 transition inline-flex items-center gap-1">← Back</button>
            <button type="button" @click="direction = 1; step = 3" class="cursor-pointer bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-gray-700 transition">Continue →</button>
        </div>
    </x-setup.step>

    {{-- Step 3: verify --}}
    <x-setup.step :n="3">
        <p class="text-xs font-semibold text-orange-600 mb-1">Step 3 / 3</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Verify</h2>
        <p class="text-sm text-gray-600">Open <a href="https://claude.ai" target="_blank" rel="noopener" class="text-orange-600 underline hover:text-orange-700">claude.ai</a>, send a message, then wait a couple minutes to see if you're asked to paste a token (it only asks once).</p>
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800 mt-3">
            Claude Desktop is only tracked while <a href="https://claude.ai" target="_blank" rel="noopener" class="underline">claude.ai</a> is open in this browser (it syncs roughly once a minute via the userscript). Chatting directly in a browser tab gets picked up within seconds.
        </div>
        <button type="button" @click="direction = -1; step = 2" class="cursor-pointer mt-4 text-sm font-semibold text-gray-500 hover:text-orange-600 transition inline-flex items-center gap-1">← Back</button>
    </x-setup.step>
</div>
