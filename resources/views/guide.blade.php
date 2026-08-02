{{-- resources/views/guide.blade.php --}}
@extends('layouts.app')

@section('content')
    @include('partials.account-nav', ['active' => 'guide'])

<div class="max-w-3xl mx-auto p-8 space-y-8">
    <h1 class="text-2xl font-bold text-gray-900">CLI command reference</h1>
    <p class="text-sm text-gray-500">Everything <code class="text-gray-700">token-slayer</code> can do once it's installed, and how to customize what your fighter shows.</p>

    <section class="bg-white border border-gray-200 rounded-xl p-6">
        <h2 class="text-sm font-bold text-gray-900 mb-4">Using more than one Claude account?</h2>
        <p class="text-sm text-gray-600 mb-4">
            <code>token-slayer</code> is a small CLI/TUI that manages Claude Code login slots on this
            machine and keeps attribution pointed at whichever one is active. You only need it if you
            switch between more than one Claude account.
        </p>
        <div class="bg-amber-50 border border-amber-200 text-amber-800 text-xs rounded-lg px-3 py-2 mb-4">
            <span class="font-medium">macOS:</span> the first <code>token-slayer switch</code> (or
            <code>setup</code>) pops a Keychain prompt asking for your login password/Touch ID — choose
            <em>Always Allow</em> to skip repeat prompts. On a brand-new Mac, <code>python3</code> may be
            an Xcode stub that pops its own "Install Command Line Developer Tools?" dialog — check with
            <code>xcode-select -p</code> first (empty output → run <code>xcode-select --install</code>).
        </div>
        <p class="text-sm text-gray-600 mb-2">
            If an admin provisioned an org account for you, pull it and configure Claude Code in one step:
        </p>
        <pre class="bg-gray-900 text-gray-100 rounded-lg p-3 text-xs overflow-x-auto mb-4">token-slayer setup</pre>
        <p class="text-sm text-gray-600 mb-4">
            <span class="font-medium text-gray-700">Adding another personal account:</span> log into it in
            Claude Code itself (<code>claude</code>, then <code>/login</code>), then run
            <code>token-slayer add NAME</code> to snapshot it. For an org account, don't use
            <code>add</code> — ask an admin to provision it, then run <code>token-slayer setup</code> above.
        </p>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-gray-500 uppercase">
                    <tr><th class="py-2">Command</th><th>What it does</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr><td class="py-2 font-mono text-xs align-top">token-slayer</td><td class="align-top text-gray-600">launches the interactive TUI (browse slots, switch, live usage)</td></tr>
                    <tr><td class="py-2 font-mono text-xs align-top">token-slayer list</td><td class="align-top text-gray-600">lists account slots, marking the active one</td></tr>
                    <tr><td class="py-2 font-mono text-xs align-top">token-slayer current</td><td class="align-top text-gray-600">prints just the active slot's name and email/org</td></tr>
                    <tr><td class="py-2 font-mono text-xs align-top">token-slayer add NAME</td><td class="align-top text-gray-600">adds a slot from the machine's current login</td></tr>
                    <tr><td class="py-2 font-mono text-xs align-top">token-slayer switch NAME</td><td class="align-top text-gray-600">switches the active Claude account</td></tr>
                    <tr><td class="py-2 font-mono text-xs align-top">token-slayer alias NAME ALIAS</td><td class="align-top text-gray-600">sets/clears a short alias for a slot</td></tr>
                    <tr><td class="py-2 font-mono text-xs align-top">token-slayer remove NAME</td><td class="align-top text-gray-600">removes a slot (falls back to another remaining account if any are left)</td></tr>
                    <tr><td class="py-2 font-mono text-xs align-top">token-slayer status</td><td class="align-top text-gray-600">prints version, namespace, active account, and credential status</td></tr>
                    <tr><td class="py-2 font-mono text-xs align-top">token-slayer setup</td><td class="align-top text-gray-600">pulls an admin-provisioned org account and configures Claude Code in one step</td></tr>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-400 mt-3"><code>token-slayer --help</code> or <code>token-slayer &lt;command&gt; --help</code> for full details on any of these. Newer installs also answer to the short alias <code>tok</code> — the installer creates it as a real symlink alongside <code>token-slayer</code>, not a shell alias, so it works from any shell.</p>
    </section>

    <section class="bg-white border border-gray-200 rounded-xl p-6">
        <h2 class="text-sm font-bold text-gray-900 mb-4">Customize what your fighter shows</h2>
        <p class="text-sm text-gray-600 mb-4">
            By default the charging bubble shows only a privacy-safe tool name — no commands, file paths,
            or prompts. Create <code>~/.config/{{ $namespace }}/custom.sh</code> and it will be sourced by
            every hook call right before the event is sent, with <code>$BODY</code> (the JSON payload) in
            scope for you to edit with <code>jq</code>. The installer creates the
            <code>~/.config/{{ $namespace }}</code> directory but never touches or overwrites this file, so
            it survives every install and update. Set <code>custom_activity</code> in <code>$BODY</code>
            and the server shows it verbatim instead of its own default label.
        </p>
        <div class="overflow-x-auto mb-4">
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
        <p class="text-sm text-gray-600 mb-1">Example <code>~/.config/{{ $namespace }}/custom.sh</code>:</p>
        <pre class="bg-gray-900 text-gray-100 rounded-lg p-3 text-xs overflow-x-auto">if command -v jq >/dev/null 2>&1; then
  BODY=$(printf '%s' "$BODY" | jq -c '
    if (.hook_event_name // "") == "UserPromptSubmit" then
      .custom_activity = "New prompt"
    elif (.hook_event_name // "") == "PreToolUse" then
      .custom_activity = ({
        "Bash": "Execute",
        "Task": ("Agent: " + (.tool_input.description // "subagent"))
      }[.tool_name] // .tool_name)
    else . end' 2>/dev/null || printf '%s' "$BODY")
fi</pre>
    </section>
</div>
@endsection
