<?php

test('claude snippet lists exactly the events the install script itself registers, no more', function () {
    // PostToolUse, SessionEnd and Notification fall through to a bare 201 on
    // the server and PostToolUse carries tool_response -- registering it
    // here would leak that content for no benefit, purely because the
    // manual snippet drifted from what the real installer wires up.
    $rendered = view('partials.claude-snippet', [
        'baseUrl' => 'https://app/api/events',
        'namespace' => 'token_slayer',
    ])->render();

    foreach (['SessionStart', 'UserPromptSubmit', 'PreToolUse', 'Stop', 'SubagentStop'] as $hook) {
        expect($rendered)->toContain($hook);
    }
    foreach (['PostToolUse', 'SessionEnd', 'Notification'] as $hook) {
        expect($rendered)->not->toContain($hook);
    }

    expect($rendered)->toContain('bash $HOME/.config/token_slayer/send-hook.sh');
    expect(json_decode($rendered, true))->toBeArray();
});

test('claude snippet uses the namespace in the helper path', function () {
    $rendered = view('partials.claude-snippet', [
        'baseUrl' => 'https://app/api/events',
        'namespace' => 'acme',
    ])->render();

    expect($rendered)
        ->toContain('$HOME/.config/acme/send-hook.sh')
        ->not->toContain('$HOME/.config/token_slayer/send-hook.sh');
});

test('codex snippet calls the helper with PROVIDER=codex, including SubagentStop', function () {
    // Codex's SubagentStop payload carries the subagent's own transcript, not
    // the parent's -- omitting it here (as the snippet used to) makes every
    // Task-dispatched subagent's usage invisible for anyone using this manual
    // config instead of the real installer.
    $rendered = view('partials.codex-snippet', [
        'baseUrl' => 'https://app/api/events',
        'namespace' => 'token_slayer',
    ])->render();

    expect($rendered)
        ->toContain('PROVIDER=codex bash $HOME/.config/token_slayer/send-hook.sh')
        ->toContain('SessionStart')
        ->toContain('Stop')
        ->toContain('SubagentStop')
        ->not->toContain('config.toml');
    expect(json_decode($rendered, true))->toBeArray();
});

test('antigravity snippet calls the helper with PROVIDER=antigravity, without PostToolUse', function () {
    // The server does nothing with PostToolUse and it carries tool_response
    // -- the real installer deliberately never registers it, so the manual
    // snippet must not either.
    $rendered = view('partials.antigravity-snippet', [
        'baseUrl' => 'https://app/api/events',
        'namespace' => 'aiorg',
    ])->render();

    expect($rendered)
        ->toContain('PROVIDER=antigravity bash $HOME/.config/aiorg/send-hook.sh')
        ->toContain('SessionStart')
        ->toContain('PreInvocation')
        ->toContain('PreToolUse')
        ->toContain('Stop')
        ->not->toContain('PostToolUse');
});
