<div class="p-8 max-w-3xl mx-auto space-y-6">
    <header class="flex items-center gap-4">
        <img src="{{ $user->avatar_url }}" class="w-16 h-16 rounded-full">
        <div>
            <h1 class="text-2xl font-semibold">{{ $user->display_name }}</h1>
            <p class="text-gray-500">@ {{ $user->slack_handle }}</p>
        </div>
    </header>

    <section class="border rounded p-4">
        <h2 class="font-semibold mb-2">Hook token</h2>
        @if ($plainToken)
            <code class="block bg-gray-100 p-2 rounded select-all">{{ $plainToken }}</code>
            <p class="text-sm text-gray-500 mt-2">Shown once — copy it now.</p>
        @else
            <p class="text-gray-500">Token is set. Regenerate to view a new one.</p>
        @endif
        <button wire:click="regenerate" class="mt-3 px-3 py-1 bg-red-600 text-white rounded">Regenerate</button>
    </section>

    <section class="border rounded p-4">
        <h2 class="font-semibold mb-2">Claude Code hook config</h2>
        <p class="text-sm mb-2">Paste into <code>~/.claude/settings.json</code> under the top level.</p>
        <pre class="bg-gray-900 text-gray-100 p-3 rounded overflow-x-auto text-xs">{{ $claudeSnippet }}</pre>
    </section>

    <section class="border rounded p-4">
        <h2 class="font-semibold mb-2">Codex hook config</h2>
        <p class="text-sm mb-2">Paste into <code>~/.codex/config.toml</code> under <code>[[hooks]]</code> blocks.</p>
        <pre class="bg-gray-900 text-gray-100 p-3 rounded overflow-x-auto text-xs">{{ $codexSnippet }}</pre>
    </section>
</div>
