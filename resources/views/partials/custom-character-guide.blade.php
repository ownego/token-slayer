{{-- The prompt guide on /character: pick a character type, get its costume prompt, copy it into an image AI.
     $types: label by type key; $prompts: prompt text by type key; $thumbs: where the type thumbnails are served from.
     Static (no Livewire state), so the page and its editor never wipe what was typed. --}}
<section class="bg-white border border-gray-200 rounded-xl p-4 sm:p-5 space-y-4" x-data="characterPrompt(@js($types), @js($prompts), @js($thumbs))">
    <h2 class="font-bold text-gray-900">1. Ask an image AI for a pose sheet</h2>
    <p class="text-sm text-gray-600">Pick a costume by character type below, then copy its prompt and paste it into ChatGPT (or another image AI) together with a picture of your custom character to make your pose sheet. The prompt already spells out that type's costume; write into a line under COSTUME ITEMS only to change that item, and leave it empty to keep the type's default. If one AI refuses your character, try another.</p>

    <div class="grid grid-cols-3 gap-2 sm:grid-cols-5 md:grid-cols-9" role="group" aria-label="Character type">
        <template x-for="(label, key) in types" :key="key">
            <button type="button" x-on:click="choose(key)" x-bind:aria-pressed="type === key"
                class="flex min-h-11 cursor-pointer flex-col items-center gap-1 rounded-lg border px-1 pb-1.5 pt-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                x-bind:class="type === key ? 'border-orange-500 bg-orange-50 text-orange-700 ring-2 ring-orange-300' : 'border-gray-200 bg-white text-gray-700 hover:border-orange-400 hover:text-orange-600'">
                <img x-bind:src="thumbs + '/' + key + '.png'" alt="" width="64" height="64" class="h-16 w-16" loading="lazy">
                <span x-text="label"></span>
            </button>
        </template>
    </div>

    <p class="text-sm text-gray-500" x-show="! type">Choose a character type to get its prompt.</p>

    <div x-show="type" x-cloak>
        <textarea x-ref="prompt" rows="22" class="w-full text-xs font-mono border border-gray-200 rounded-lg p-3 bg-gray-50" x-model="text" aria-label="Prompt for your image AI"></textarea>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <button type="button" x-ref="copy" class="min-h-11 cursor-pointer rounded-lg border border-gray-200 px-4 text-sm text-gray-700 hover:border-orange-400 hover:text-orange-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                x-on:click="copy()" x-text="copied ? 'Copied' : 'Copy prompt'">Copy prompt</button>
            <span class="text-xs text-gray-500">Switching type keeps the costume items you typed.</span>
        </div>
    </div>
</section>
