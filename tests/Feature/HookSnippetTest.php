<?php

test('claude snippet reads token from file and includes all event hooks', function () {
    $rendered = view('partials.claude-snippet', [
        'baseUrl' => 'https://app/api/events',
        'namespace' => 'aiorg',
    ])->render();

    foreach (['SessionStart', 'UserPromptSubmit', 'PreToolUse', 'PostToolUse', 'Stop', 'SubagentStop', 'SessionEnd', 'Notification'] as $hook) {
        expect($rendered)->toContain($hook);
    }
    expect($rendered)
        ->toContain("Bearer '\$(cat ~/.config/aiorg/token)")
        ->toContain('https://app/api/events');

    expect(json_decode($rendered, true))->toBeArray();
});

test('claude snippet suppresses curl errors so unreachable endpoints stay silent', function () {
    $rendered = view('partials.claude-snippet', [
        'baseUrl' => 'https://app/api/events',
        'namespace' => 'aiorg',
    ])->render();

    expect($rendered)
        ->toContain('curl -s ')
        ->not->toContain('curl -sS')
        ->toContain('>/dev/null 2>&1');
});

test('claude snippet uses the namespace in the token path', function () {
    $rendered = view('partials.claude-snippet', [
        'baseUrl' => 'https://app/api/events',
        'namespace' => 'acme',
    ])->render();

    expect($rendered)
        ->toContain('~/.config/acme/token')
        ->not->toContain('~/.config/aiorg/token');
});

test('codex snippet reads token from file and includes a curl command', function () {
    $rendered = view('partials.codex-snippet', [
        'baseUrl' => 'https://app/api/events?provider=codex',
        'namespace' => 'aiorg',
    ])->render();

    expect($rendered)
        ->toContain("Bearer '\$(cat ~/.config/aiorg/token)")
        ->toContain('https://app/api/events?provider=codex')
        ->toContain('curl');
});

test('codex snippet suppresses curl errors so unreachable endpoints stay silent', function () {
    $rendered = view('partials.codex-snippet', [
        'baseUrl' => 'https://app/api/events?provider=codex',
        'namespace' => 'aiorg',
    ])->render();

    expect($rendered)
        ->toContain('curl -s ')
        ->not->toContain('curl -sS')
        ->toContain('>/dev/null 2>&1');
});
