<div
    x-data="{
        track: null,
        step: 1,
        copied: null,
        copy(id, text) {
            navigator.clipboard && navigator.clipboard.writeText(text).catch(() => {});
            this.copied = id;
            clearTimeout(this._copyTimer);
            this._copyTimer = setTimeout(() => { this.copied = null; }, 1300);
        },
    }"
>
    @include('partials.account-nav', ['active' => 'setup'])

    <div class="max-w-3xl mx-auto p-8">
        <template x-if="!track">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 mb-1">Set up Claude tracking</h1>
                <p class="text-sm text-gray-500 mb-6">Pick how you use Claude — install only what you need.</p>

                <div class="grid gap-4">
                    <button type="button" @click="track = 'cli'; step = 2" class="text-left bg-white border-2 border-gray-200 hover:border-orange-500 rounded-xl p-5 transition">
                        <div class="font-bold text-gray-900 mb-1">Claude CLI</div>
                        <div class="text-xs text-gray-500">Claude Code, Codex, or Antigravity in a terminal</div>
                    </button>
                    <button type="button" @click="track = 'chat'; step = 2" class="text-left bg-white border-2 border-gray-200 hover:border-orange-500 rounded-xl p-5 transition">
                        <div class="font-bold text-gray-900 mb-1">Claude chat</div>
                        <div class="text-xs text-gray-500">claude.ai or the Claude Desktop app, no terminal</div>
                    </button>
                    <button type="button" @click="track = 'cowork'; step = 2" class="text-left bg-white border-2 border-gray-200 hover:border-orange-500 rounded-xl p-5 transition">
                        <div class="font-bold text-gray-900 mb-1">Claude Cowork</div>
                        <div class="text-xs text-gray-500">Background agent tasks, no browser or terminal hooks</div>
                    </button>
                </div>
            </div>
        </template>

        <div x-show="track === 'cli'" x-cloak>
            @include('partials.setup.stepper', ['labels' => ['Công cụ', 'Nền tảng', 'Python', 'Đã có token?', 'Cài đặt', 'Xong']])
            @include('partials.setup.cli-track')
        </div>
        <div x-show="track === 'cowork'" x-cloak>
            @include('partials.setup.stepper', ['labels' => ['Công cụ', 'Nền tảng', 'Python', 'Cài đặt']])
            @include('partials.setup.cowork-track')
        </div>
        <div x-show="track === 'chat'" x-cloak>
            @include('partials.setup.stepper', ['labels' => ['Công cụ', 'Cài đặt', 'Xong']])
            @include('partials.setup.chat-track')
        </div>
    </div>
</div>
