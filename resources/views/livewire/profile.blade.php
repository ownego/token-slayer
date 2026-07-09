<div class="p-8 max-w-3xl mx-auto space-y-6">
    <header class="flex items-center gap-4">
        <img src="{{ $user->avatar_url }}" class="w-16 h-16 rounded-full">
        <div class="flex-1">
            <h1 class="text-2xl font-semibold">{{ $user->name }}</h1>
            <p class="text-gray-500">{{ $user->display_name }}</p>
        </div>
        <a href="{{ route('battlefield') }}" class="px-3 py-2 bg-slate-800/80 text-white rounded text-sm font-mono">Battlefield →</a>
    </header>

    <section class="border rounded p-4">
        <h2 class="font-semibold mb-3">Battlefield stats</h2>
        <dl class="grid grid-cols-3 gap-4 text-center">
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">All-time</dt>
                <dd class="text-xl font-mono">{{ number_format($damageTotals['allTime']) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">Monthly</dt>
                <dd class="text-xl font-mono">{{ number_format($damageTotals['monthly']) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">Daily</dt>
                <dd class="text-xl font-mono">{{ number_format($damageTotals['daily']) }}</dd>
            </div>
        </dl>
    </section>

    <section class="border rounded p-4 space-y-4">
        <h2 class="font-semibold">Usage</h2>

        <div>
            <h3 class="text-xs uppercase tracking-wide text-gray-500 mb-2">All users</h3>
            <dl class="grid grid-cols-3 gap-4 text-center">
                <div><dt class="text-xs uppercase tracking-wide text-gray-500">Hourly</dt><dd class="text-xl font-mono">{{ number_format($globalUsage['hourly']) }}</dd></div>
                <div><dt class="text-xs uppercase tracking-wide text-gray-500">Daily</dt><dd class="text-xl font-mono">{{ number_format($globalUsage['daily']) }}</dd></div>
                <div><dt class="text-xs uppercase tracking-wide text-gray-500">Monthly</dt><dd class="text-xl font-mono">{{ number_format($globalUsage['monthly']) }}</dd></div>
            </dl>
        </div>

        <div>
            <h3 class="text-xs uppercase tracking-wide text-gray-500 mb-2">You</h3>
            <dl class="grid grid-cols-3 gap-4 text-center">
                <div><dt class="text-xs uppercase tracking-wide text-gray-500">Hourly</dt><dd class="text-xl font-mono">{{ number_format($damageTotals['hourly']) }}</dd></div>
                <div><dt class="text-xs uppercase tracking-wide text-gray-500">Daily</dt><dd class="text-xl font-mono">{{ number_format($damageTotals['daily']) }}</dd></div>
                <div><dt class="text-xs uppercase tracking-wide text-gray-500">Monthly</dt><dd class="text-xl font-mono">{{ number_format($damageTotals['monthly']) }}</dd></div>
            </dl>
        </div>

        @if ($account)
            <div>
                <h3 class="text-xs uppercase tracking-wide text-gray-500 mb-2">
                    My account — {{ $account->email }}
                    <span class="text-gray-400">({{ $account->plan }}, {{ $account->users()->count() }} members)</span>
                </h3>
                <dl class="grid grid-cols-3 gap-4 text-center">
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">Hourly</dt><dd class="text-xl font-mono">{{ number_format($accountUsage['hourly']) }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">Daily</dt><dd class="text-xl font-mono">{{ number_format($accountUsage['daily']) }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">Monthly</dt><dd class="text-xl font-mono">{{ number_format($accountUsage['monthly']) }}</dd></div>
                </dl>
            </div>
        @endif
    </section>

    {{-- Shared token: every track below uses this same hook token. --}}
    <section class="border rounded p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold">Your hook token</h2>
            <button wire:click="regenerate" class="px-3 py-1 bg-red-600 text-white rounded text-sm">Regenerate token</button>
        </div>
        @if ($plainToken)
            <p class="text-sm text-gray-500 mb-1">Your token (shown once — copy it now):</p>
            <code class="block bg-gray-100 p-2 rounded select-all">{{ $plainToken }}</code>
        @else
            <p class="text-sm text-gray-500">Click <em>Regenerate token</em> to create a fresh token. It's saved to <code>{{ $tokenPath }}</code> by the installers below, or you can paste it when asked.</p>
        @endif
    </section>

    <p class="text-sm text-gray-500">
        <span class="font-semibold text-gray-700">Pick how you use Claude</span> — install only what you need. The three tracks are independent.
    </p>

    {{-- Track 1: terminal CLIs. --}}
    <section class="border rounded p-4">
        <div class="flex items-center gap-2 mb-1">
            <h2 class="font-semibold">1 · CLI</h2>
            <span class="text-xs bg-sky-100 text-sky-700 rounded px-2 py-0.5">Claude Code · Codex · Antigravity</span>
        </div>
        <p class="text-sm text-gray-500 mb-3">For developers using the CLI agents. Installs the hooks and saves your token to <code>{{ $tokenPath }}</code> in one step. Safe to re-run on rotation.</p>
        <pre class="bg-gray-900 text-gray-100 p-3 rounded overflow-x-auto text-xs select-all">{{ $combinedCommand }}</pre>
        <p class="text-xs text-gray-500 mt-2">Or inspect the script first: <a href="{{ $installUrl }}" class="underline">{{ $installUrl }}</a></p>

        <details class="mt-3">
            <summary class="text-sm font-medium cursor-pointer text-gray-600">Manual hook config (if you'd rather copy by hand)</summary>
            <div class="mt-3 space-y-3">
                <div>
                    <p class="text-sm mb-1">1. Save your token to <code>{{ $tokenPath }}</code> (the snippets below read it at runtime):</p>
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
                <div>
                    <p class="text-sm mb-1">4. Paste/merge into your global <code>~/.gemini/config/hooks.json</code> or project-level <code>.agents/hooks.json</code>:</p>
                    <pre class="bg-gray-900 text-gray-100 p-3 rounded overflow-x-auto text-xs">{{ $antigravitySnippet }}</pre>
                </div>
            </div>
        </details>
    </section>

    {{-- Track 2: browser / Desktop chat (no terminal). --}}
    <section class="border rounded p-4">
        <div class="flex items-center gap-2 mb-1">
            <h2 class="font-semibold">2 · Claude chat</h2>
            <span class="text-xs bg-emerald-100 text-emerald-700 rounded px-2 py-0.5">browser &amp; Desktop</span>
        </div>
        <p class="text-sm text-gray-500 mb-3">For anyone chatting on <a href="https://claude.ai" target="_blank" rel="noopener" class="underline">claude.ai</a> or in the Claude Desktop app (e.g. marketing). No terminal needed. Counts your chats as boss damage (estimated from reply length).</p>
        <ol class="text-sm text-gray-600 list-decimal ml-5 space-y-1.5">
            <li>Install <a href="https://chromewebstore.google.com/detail/tampermonkey/dhdgffkkebhmkfjojejmpbldmpobfkfo" target="_blank" rel="noopener" class="underline">Tampermonkey</a> (or Violentmonkey) in your browser.</li>
            <li><span class="font-medium">Chrome 138+ only:</span> open <code>chrome://extensions</code> → Tampermonkey → <em>Details</em> → turn on <span class="font-medium">Allow user scripts</span>. Without this the tracker installs but never runs.</li>
            <li><a href="{{ $userscriptUrl }}" class="underline">Install the tracker userscript</a> — your userscript manager will prompt you.</li>
            <li>Open <a href="https://claude.ai" target="_blank" rel="noopener" class="underline">claude.ai</a>, prompt something, and wait a few minutes to paste your token when asked (once).</li>
        </ol>
        <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1.5 mt-3">
            <span class="font-medium">Note:</span> Claude Desktop chats are tracked only while <a href="https://claude.ai" target="_blank" rel="noopener" class="underline">claude.ai</a> is open in this browser (picked up on the userscript's ~1-min sync). Chats in the browser tab itself register within seconds.
        </p>
    </section>

    {{-- Track 3: Cowork background watcher. --}}
    <section class="border rounded p-4">
        <div class="flex items-center gap-2 mb-1">
            <h2 class="font-semibold">3 · Claude Cowork</h2>
            <span class="text-xs bg-violet-100 text-violet-700 rounded px-2 py-0.5">background · no browser</span>
        </div>
        <p class="text-sm text-gray-500 mb-3">For Claude Cowork agent tasks. A small watcher reads Cowork transcripts every 2 minutes and reports exact token usage — no browser, no terminal hooks. macOS &amp; Linux.</p>
        <pre class="bg-gray-900 text-gray-100 p-3 rounded overflow-x-auto text-xs select-all">{{ $coworkCommand }}</pre>
        <p class="text-xs text-gray-500 mt-2">Or inspect the script first: <a href="{{ $coworkInstallUrl }}" class="underline">{{ $coworkInstallUrl }}</a></p>
    </section>
</div>
