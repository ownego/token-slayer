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
        <dl class="grid grid-cols-4 gap-4 text-center">
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">Hourly</dt>
                <dd class="text-xl font-mono">{{ number_format($damageTotals['hourly']) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">Daily</dt>
                <dd class="text-xl font-mono">{{ number_format($damageTotals['daily']) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">Monthly</dt>
                <dd class="text-xl font-mono">{{ number_format($damageTotals['monthly']) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">All-time</dt>
                <dd class="text-xl font-mono">{{ number_format($damageTotals['allTime']) }}</dd>
            </div>
        </dl>
    </section>

    <section class="border rounded p-4">
        <h2 class="font-semibold mb-3">Attribution</h2>
        @php($event = $attribution['event'])
        @if ($event)
            @if ($event->account_id)
                <p class="text-sm text-emerald-700 bg-emerald-50 border border-emerald-200 rounded px-2 py-1.5">
                    Your latest usage matched <span class="font-medium">{{ $event->account->email }}</span> — an org account.
                </p>
            @elseif ($event->account_source === 'proxy')
                <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1.5">
                    Your latest usage went through a proxy (<code>ANTHROPIC_BASE_URL</code> is set), so the account couldn't be detected. Set <code>account.json</code> to attribute it manually.
                </p>
            @elseif ($event->account_email)
                <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1.5">
                    Your latest usage claimed <span class="font-medium">{{ $event->account_email }}</span>, which isn't a known org account — counted as personal usage. Tell an admin if this should be an org account.
                </p>
            @else
                <p class="text-sm text-gray-500">Your latest usage wasn't tied to any account — counted as personal usage.</p>
            @endif
        @else
            <p class="text-sm text-gray-500">No usage recorded yet.</p>
        @endif
        @if ($attribution['outdated'])
            <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1.5 mt-2">
                Your client is running an outdated version{{ $attribution['clientVersion'] ? " ({$attribution['clientVersion']})" : '' }}. Run <code>token-slayer update</code> to get the latest.
            </p>
        @endif
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

        @if (count($accountRows) > 0)
            <div>
                <h3 class="text-xs uppercase tracking-wide text-gray-500 mb-2">Your accounts</h3>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($accountRows as $row)
                        <div class="border rounded p-3">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium">{{ $row['email'] }}</span>
                                <span class="text-xs bg-gray-100 text-gray-600 rounded px-2 py-0.5">{{ $row['memberCount'] }} {{ Str::plural('member', $row['memberCount']) }}</span>
                            </div>
                            <p class="text-xs text-gray-400 mb-2">{{ $row['plan'] ?? 'no plan on file' }}</p>
                            @unless ($row['isMember'])
                                <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1 mb-2">You're not a member of this account — usage was attributed here but you may lose access.</p>
                            @endunless
                            <dl class="grid grid-cols-3 gap-2 text-center">
                                <div><dt class="text-xs uppercase tracking-wide text-gray-500">Hourly</dt><dd class="text-sm font-mono">{{ number_format($row['hourly']) }}</dd></div>
                                <div><dt class="text-xs uppercase tracking-wide text-gray-500">Daily</dt><dd class="text-sm font-mono">{{ number_format($row['daily']) }}</dd></div>
                                <div><dt class="text-xs uppercase tracking-wide text-gray-500">Monthly</dt><dd class="text-sm font-mono">{{ number_format($row['monthly']) }}</dd></div>
                            </dl>
                            @if (! is_null($row['util_5h']) || ! is_null($row['util_7d']))
                                <div class="mt-3 space-y-2">
                                    @foreach ($quotaBars($row) as $bar)
                                        <div>
                                            <div class="flex justify-between text-xs text-gray-500 mb-0.5"><span>{{ $bar['label'] }}</span><span class="font-mono">{{ $bar['pct'] }}%</span></div>
                                            <div class="h-1.5 bg-gray-200 rounded overflow-hidden"><div class="h-full {{ $bar['band'] }}" style="width: {{ min($bar['pct'], 100) }}%"></div></div>
                                        </div>
                                    @endforeach
                                    @if ($row['lastProbedAt'])
                                        <p class="text-xs text-gray-400">probed {{ $row['lastProbedAt']->diffForHumans() }}</p>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
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
        <p class="text-xs text-gray-500 mt-1">Already installed? Run <code>token-slayer update</code>.</p>

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

        <details class="mt-3">
            <summary class="text-sm font-medium cursor-pointer text-gray-600">Customize what your fighter shows</summary>
            <div class="mt-3 space-y-3">
                <p class="text-sm text-gray-600">
                    By default the charging bubble shows only a privacy-safe tool name — no commands, file paths, or prompts. Create
                    <code>~/.config/{{ $namespace }}/custom.sh</code> and it will be sourced by every hook call right before the event is sent,
                    with <code>$BODY</code> (the JSON payload) in scope for you to edit with <code>jq</code>. The installer creates the
                    <code>~/.config/{{ $namespace }}</code> directory but never touches or overwrites this file, so it survives every install and update.
                    Set <code>custom_activity</code> in <code>$BODY</code> and the server shows it verbatim instead of its own default label.
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs text-gray-500 uppercase">
                            <tr>
                                <th class="py-2">Provider</th>
                                <th>Example <code>tool_name</code></th>
                                <th>Useful <code>tool_input</code> fields</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr>
                                <td class="py-2 font-medium align-top">Claude Code</td>
                                <td class="align-top"><code>Bash</code>, <code>Read</code>, <code>Edit</code>, <code>Write</code>, <code>Grep</code>, <code>WebFetch</code>, <code>Task</code></td>
                                <td class="align-top"><code>command</code>, <code>file_path</code>, <code>pattern</code>, <code>url</code>, <code>description</code></td>
                            </tr>
                            <tr>
                                <td class="py-2 font-medium align-top">Any provider · MCP tools</td>
                                <td class="align-top"><code>mcp__&lt;server&gt;__&lt;tool&gt;</code>, e.g. <code>mcp__jira__jira_search_issues</code></td>
                                <td class="align-top">shape varies per tool; the server name (segment after the first <code>__</code>) is the most reliable thing to key off</td>
                            </tr>
                            <tr>
                                <td class="py-2 font-medium align-top">Antigravity</td>
                                <td class="align-top"><code>run_command</code>, <code>read_file</code>, <code>write_file</code>, <code>grep_search</code></td>
                                <td class="align-top"><code>CommandLine</code>, <code>AbsolutePath</code>, <code>TargetFile</code>, <code>Query</code></td>
                            </tr>
                            <tr>
                                <td class="py-2 font-medium align-top">Codex CLI</td>
                                <td class="align-top text-gray-400" colspan="2">no per-tool events today — only session start/stop are wired, so there's nothing to key off yet</td>
                            </tr>
                            <tr>
                                <td class="py-2 font-medium align-top">claude.ai / Cowork</td>
                                <td class="align-top text-gray-400" colspan="2">no tool events — these only ever report a token count on session end</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div>
                    <p class="text-sm mb-1">Example <code>~/.config/{{ $namespace }}/custom.sh</code>:</p>
                    <pre class="bg-gray-900 text-gray-100 p-3 rounded overflow-x-auto text-xs select-all">if command -v jq >/dev/null 2>&1; then
  BODY=$(printf '%s' "$BODY" | jq -c '
    if (.hook_event_name // "") == "UserPromptSubmit" then
      .custom_activity = "🧠 New prompt"
    elif (.hook_event_name // "") == "PreToolUse" then
      .custom_activity = ({
        "Bash": "⚔️ All-In Execute",
        "Task": ("Agent: " + (.tool_input.description // "subagent"))
      }[.tool_name] // .tool_name)
    else . end' 2>/dev/null || printf '%s' "$BODY")
fi</pre>
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
