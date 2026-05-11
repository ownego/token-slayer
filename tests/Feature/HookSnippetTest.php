<?php

test('claude snippet contains user token and all event hooks', function () {
    $rendered = view('partials.claude-snippet', [
        'token' => 'tok-xyz',
        'baseUrl' => 'https://app/api/events',
    ])->render();

    foreach (['SessionStart', 'UserPromptSubmit', 'PreToolUse', 'PostToolUse', 'Stop', 'SubagentStop', 'SessionEnd', 'Notification'] as $hook) {
        expect($rendered)->toContain($hook);
    }
    expect($rendered)
        ->toContain('Bearer tok-xyz')
        ->toContain('https://app/api/events');

    expect(json_decode($rendered, true))->toBeArray();
});

test('codex snippet contains user token and a curl command', function () {
    $rendered = view('partials.codex-snippet', [
        'token' => 'tok-xyz',
        'baseUrl' => 'https://app/api/events?provider=codex',
    ])->render();

    expect($rendered)
        ->toContain('Bearer tok-xyz')
        ->toContain('https://app/api/events?provider=codex')
        ->toContain('curl');
});
