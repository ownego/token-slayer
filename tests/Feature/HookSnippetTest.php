<?php

test('claude snippet calls the install-script helper and lists every claude code event', function () {
    $rendered = view('partials.claude-snippet', [
        'baseUrl' => 'https://app/api/events',
        'namespace' => 'token_slayer',
    ])->render();

    foreach (['SessionStart', 'UserPromptSubmit', 'PreToolUse', 'PostToolUse', 'Stop', 'SubagentStop', 'SessionEnd', 'Notification'] as $hook) {
        expect($rendered)->toContain($hook);
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

test('codex snippet calls the helper with PROVIDER=codex', function () {
    $rendered = view('partials.codex-snippet', [
        'baseUrl' => 'https://app/api/events',
        'namespace' => 'token_slayer',
    ])->render();

    expect($rendered)
        ->toContain('PROVIDER=codex bash $HOME/.config/token_slayer/send-hook.sh')
        ->toContain('session_start')
        ->toContain('stop');
});
