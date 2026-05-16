<div class="p-8 max-w-3xl mx-auto space-y-6">
    <header class="flex items-center gap-4">
        <img src="{{ $user->avatar_url }}" class="w-16 h-16 rounded-full">
        <div>
            <h1 class="text-2xl font-semibold">{{ $user->display_name }}</h1>
            <p class="text-gray-500">@ {{ $user->slack_handle }}</p>
        </div>
    </header>

    <section class="border rounded p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold">Hook setup</h2>
            <button wire:click="regenerate" class="px-3 py-1 bg-red-600 text-white rounded text-sm">Regenerate token</button>
        </div>
        @if ($plainToken)
            <p class="text-sm text-gray-500 mb-1">Your token (shown once):</p>
            <code class="block bg-gray-100 p-2 rounded select-all">{{ $plainToken }}</code>
            <p class="text-sm text-gray-500 mt-3">The command below installs the Claude Code + Codex CLI hooks and saves this token to <code>~/.config/aiorg/token</code> in one step. Safe to re-run on rotation.</p>
        @else
            <p class="text-sm text-gray-500 mb-2">Installs Claude Code + Codex CLI hooks and saves your token to <code>~/.config/aiorg/token</code> in one step. Click <em>Regenerate token</em> above to bake a fresh token into the command, or substitute your existing token below.</p>
        @endif
        <pre class="bg-gray-900 text-gray-100 p-3 rounded overflow-x-auto text-xs select-all">{{ $combinedCommand }}</pre>
        <p class="text-xs text-gray-500 mt-2">Or inspect the script first: <a href="{{ $installUrl }}" class="underline">{{ $installUrl }}</a></p>
    </section>

    <details class="border rounded p-4">
        <summary class="font-semibold cursor-pointer">Manual hook config (if you'd rather copy by hand)</summary>
        <div class="mt-3 space-y-3">
            <div>
                <p class="text-sm mb-1">1. Save your token to <code>~/.config/aiorg/token</code> (the snippets below read it at runtime):</p>
                <pre class="bg-gray-900 text-gray-100 p-3 rounded overflow-x-auto text-xs select-all">{{ $tokenSaveCommand }}</pre>
            </div>
            <div>
                <p class="text-sm mb-1">2. Paste into <code>~/.claude/settings.json</code> under the top level:</p>
                <pre class="bg-gray-900 text-gray-100 p-3 rounded overflow-x-auto text-xs">{{ $claudeSnippet }}</pre>
            </div>
            <div>
                <p class="text-sm mb-1">3. Append to <code>~/.codex/config.toml</code>:</p>
                <pre class="bg-gray-900 text-gray-100 p-3 rounded overflow-x-auto text-xs">{{ $codexSnippet }}</pre>
            </div>
        </div>
    </details>
</div>
