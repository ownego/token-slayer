{{-- resources/views/guide.blade.php --}}
@extends('layouts.app')

@php
    $accountTasks = [
        [
            'q' => 'See all your accounts',
            'cmd' => 'tok',
            'desc' => 'Launches the interactive TUI — browse every slot, see live usage, and switch right from there.',
            'example' => 'tok',
        ],
        [
            'q' => 'Add another account',
            'cmd' => 'tok add NAME',
            'desc' => 'Log into the account in Claude Code first (<code>claude</code>, then <code>/login</code>), then run this — it saves the current login as a new slot under the name you give it.',
            'example' => 'tok add work',
        ],
        [
            'q' => 'Switch which account is active',
            'cmd' => 'tok switch NAME  (or an index number)',
            'desc' => "Switches which Claude account is active on this machine. Target a slot by the name you gave it with <code>add</code>, or by its position number — whichever's faster.",
            'example' => "tok switch work\n# or, by index:\ntok switch 2",
            'tip' => 'Not sure of the name or index? Run bare <code>tok</code> — it lists every slot with its index and lets you switch right there.',
        ],
        [
            'q' => 'See which account is currently active',
            'cmd' => 'tok current',
            'desc' => "Prints just the active slot's name and email/org — a quick check without the full list.",
            'example' => 'tok current',
        ],
        [
            'q' => 'Check version, namespace, and credential status',
            'cmd' => 'tok status',
            'desc' => "Prints the installed version, hook namespace, active account, and whether credentials look valid — useful when something's not tracking.",
            'example' => 'tok status',
        ],
        [
            'q' => 'Set or clear a short alias for a slot',
            'cmd' => 'tok alias NAME ALIAS',
            'desc' => 'Gives an existing slot a shorter name to type. Pass an empty alias to clear it.',
            'example' => 'tok alias work w',
        ],
        [
            'q' => 'Remove an account slot',
            'cmd' => 'tok remove NAME',
            'desc' => 'Removes a slot. If you have other accounts left, one of them becomes active automatically.',
            'example' => 'tok remove old-account',
        ],
        [
            'q' => 'Pull an org-provisioned account',
            'cmd' => 'tok setup',
            'desc' => 'For accounts an admin already provisioned for you — pulls it and configures Claude Code in one step, no manual login needed.',
            'example' => 'tok setup',
        ],
    ];

    $customTasks = [
        [
            'q' => 'Show something custom instead of the default label',
            'cmd' => "~/.config/{$namespace}/custom.sh",
            'desc' => "The <code>~/.config/{$namespace}</code> folder already exists after install — you just add this file yourself. It's sourced right before every event is sent, with <code>\$BODY</code> (the JSON payload) in scope to edit with <code>jq</code>. It survives every install and update since installers never touch or overwrite it. Set <code>custom_activity</code> in <code>\$BODY</code> and the server shows it verbatim instead of its own default label.",
            'example' => "if command -v jq >/dev/null 2>&1; then\n  BODY=\$(printf '%s' \"\$BODY\" | jq -c '\n    if (.hook_event_name // \"\") == \"UserPromptSubmit\" then\n      .custom_activity = \"New prompt\"\n    elif (.hook_event_name // \"\") == \"PreToolUse\" then\n      .custom_activity = ({\n        \"Bash\": \"Execute\",\n        \"Task\": (\"Agent: \" + (.tool_input.description // \"subagent\"))\n      }[.tool_name] // .tool_name)\n    else . end' 2>/dev/null || printf '%s' \"\$BODY\")\nfi",
        ],
    ];

    $topics = [
        'accounts' => ['label' => 'Accounts', 'tasks' => $accountTasks],
        'custom' => ['label' => 'Custom hook display', 'tasks' => $customTasks],
    ];
@endphp

@section('content')
    @include('partials.account-nav', ['active' => 'guide'])

    <div class="max-w-3xl mx-auto p-8" x-data="{ topic: 'accounts', openTask: null }">
        <h1 class="text-2xl font-bold text-gray-900 mb-1">Command reference</h1>
        <p class="text-sm text-gray-500 mb-6">Pick what you want to do — the command shows up below. Everything here also has the full form <code class="text-gray-700">token-slayer</code>, in case <code class="text-gray-700">tok</code> isn't on PATH yet.</p>

        <div class="flex gap-6">
            <nav class="w-44 flex-shrink-0 space-y-1">
                @foreach ($topics as $key => $t)
                    <button
                        type="button"
                        @click="topic = '{{ $key }}'; openTask = null"
                        class="cursor-pointer block w-full text-left px-3 py-2.5 rounded-lg text-sm font-semibold transition"
                        :class="topic === '{{ $key }}' ? 'bg-orange-50 text-orange-600' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900'"
                    >{{ $t['label'] }}</button>
                @endforeach
            </nav>

            <div class="flex-1 min-w-0">
                <div x-show="topic === 'accounts'" x-cloak>
                    <div class="bg-amber-50 border border-amber-200 text-amber-800 text-xs rounded-lg px-3 py-2 mb-4">
                        <span class="font-medium">macOS:</span> the first <code>tok switch</code> (or <code>setup</code>) pops a Keychain prompt asking for your login password/Touch ID — choose
                        <em>Always Allow</em> to skip repeat prompts. On a brand-new Mac, <code>python3</code> may be
                        an Xcode stub that pops its own "Install Command Line Developer Tools?" dialog — check with
                        <code>xcode-select -p</code> first (empty output → run <code>xcode-select --install</code>).
                    </div>
                    @foreach ($accountTasks as $i => $task)
                        @include('partials.guide.task', ['task' => $task, 'topicKey' => 'accounts', 'i' => $i])
                    @endforeach
                    <p class="text-xs text-gray-400 mt-3">
                        <code>tok --help</code> or <code>tok &lt;command&gt; --help</code> for full details on any of these.
                    </p>
                </div>

                <div x-show="topic === 'custom'" x-cloak>
                    <p class="text-sm text-gray-600 mb-4">By default the charging bubble shows only a privacy-safe tool name — no commands, file paths, or prompts.</p>
                    @foreach ($customTasks as $i => $task)
                        @include('partials.guide.task', ['task' => $task, 'topicKey' => 'custom', 'i' => $i])
                    @endforeach

                    <div class="overflow-x-auto mt-4">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs text-gray-500 uppercase">
                                <tr><th class="py-2">Provider</th><th>Example <code>tool_name</code></th><th>Useful <code>tool_input</code> fields</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr>
                                    <td class="py-2 font-medium align-top">Claude Code</td>
                                    <td class="align-top text-gray-600"><code>Bash</code>, <code>Read</code>, <code>Edit</code>, <code>Write</code>, <code>Grep</code>, <code>WebFetch</code>, <code>Task</code></td>
                                    <td class="align-top text-gray-600"><code>command</code>, <code>file_path</code>, <code>pattern</code>, <code>url</code>, <code>description</code></td>
                                </tr>
                                <tr>
                                    <td class="py-2 font-medium align-top">Any provider · MCP tools</td>
                                    <td class="align-top text-gray-600"><code>mcp__&lt;server&gt;__&lt;tool&gt;</code>, e.g. <code>mcp__jira__jira_search_issues</code></td>
                                    <td class="align-top text-gray-600">shape varies per tool; the server name (segment after the first <code>__</code>) is the most reliable thing to key off</td>
                                </tr>
                                <tr>
                                    <td class="py-2 font-medium align-top">Antigravity</td>
                                    <td class="align-top text-gray-600"><code>run_command</code>, <code>read_file</code>, <code>write_file</code>, <code>grep_search</code></td>
                                    <td class="align-top text-gray-600"><code>CommandLine</code>, <code>AbsolutePath</code>, <code>TargetFile</code>, <code>Query</code></td>
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
                </div>
            </div>
        </div>
    </div>
@endsection
