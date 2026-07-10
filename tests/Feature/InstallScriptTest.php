<?php

beforeEach(fn () => config(['app.hook_namespace' => 'token_slayer']));

test('install.sh is publicly accessible as a shell script', function () {
    $response = $this->get('/install');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/x-shellscript');
});

test('install.sh embeds the events URL and points the hook command at the local token file', function () {
    $script = $this->get('/install')->getContent();

    expect($script)
        ->toContain('#!/bin/sh')
        ->toContain(url('/api/events'))
        ->toContain('TOKEN_FILE="$HOME/.config/token_slayer/token"')
        ->toContain('Bearer $(cat "$TOKEN_FILE")');
});

test('install.sh drops a hook helper script that enriches Stop events with transcript tokens', function () {
    $script = $this->get('/install')->getContent();

    expect($script)
        ->toContain('HELPER="$HOME/.config/token_slayer/send-hook.sh"')
        ->toContain("cat > \"\$HELPER\" <<'HOOK_SH'")
        ->toContain('chmod +x "$HELPER"')
        ->toContain('transcript_path')
        ->toContain('output_tokens')
        ->toContain('CLAUDE_CMD="bash $HELPER"')
        ->toContain('CODEX_CMD="PROVIDER=codex bash $HELPER"');
});

test('install.sh covers every claude code hook event', function () {
    $script = $this->get('/install')->getContent();

    foreach (['SessionStart', 'UserPromptSubmit', 'PreToolUse', 'PostToolUse', 'Stop', 'SubagentStop', 'SessionEnd', 'Notification'] as $event) {
        expect($script)->toContain($event);
    }
});

test('install.sh covers every antigravity CLI hook event', function () {
    $script = $this->get('/install')->getContent();

    foreach (['SessionStart', 'PreInvocation', 'PreToolUse', 'PostToolUse', 'Stop'] as $event) {
        expect($script)->toContain($event);
    }
});

test('install.sh writes to claude settings, codex config, and antigravity hooks and uses idempotent markers', function () {
    $script = $this->get('/install')->getContent();

    expect($script)
        ->toContain('$HOME/.claude/settings.json')
        ->toContain('$HOME/.codex/config.toml')
        ->toContain('$HOME/.gemini/config/hooks.json')
        ->toContain('# >>> token_slayer hooks')
        ->toContain('# <<< token_slayer hooks');
});

test('install.sh saves TOKEN_SLAYER_TOKEN to the token file when present', function () {
    $script = $this->get('/install')->getContent();

    expect($script)
        ->toContain('${TOKEN_SLAYER_TOKEN:-}')
        ->toContain('printf \'%s\' "$TOKEN_SLAYER_TOKEN"')
        ->toContain('chmod 600 "$TOKEN_FILE"');
});

test('install.sh uses the configured hook namespace in paths, env var, and markers', function () {
    config(['app.hook_namespace' => 'acme']);

    $script = $this->get('/install')->getContent();

    expect($script)
        ->toContain('~/.config/acme/token')
        ->toContain('${ACME_TOKEN:-}')
        ->toContain('# >>> acme hooks')
        ->toContain('# <<< acme hooks')
        ->not->toContain('token_slayer')
        ->not->toContain('TOKEN_SLAYER_TOKEN');
});

it('upserts hooks instead of replacing foreign entries', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)->toContain('send-hook.sh" not in json.dumps')   // fingerprint filter
        ->and($script)->not->toContain('data["hooks"][event] = [{');  // old clobbering assignment
});

it('ships account resolution and version stamping', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)->toContain('resolve_account')
        ->and($script)->toContain('account.json')
        ->and($script)->toContain('identity-cache.json')
        ->and($script)->toContain('/api/oauth/profile')
        ->and($script)->toContain('ANTHROPIC_BASE_URL')
        ->and($script)->toContain(config('token_slayer.client_version'));
});

it('installs the token-slayer CLI helper with update and status commands', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)->toContain('.local/bin/token-slayer')
        ->and($script)->toContain('already up to date');
});

it('sources the user custom.sh before sending', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('CUSTOM_SH="$HOME/.config/token_slayer/custom.sh"')
        ->toContain('[ -r "$CUSTOM_SH" ] && . "$CUSTOM_SH"');

    $customShPosition = strpos($script, 'CUSTOM_SH="$HOME/.config/token_slayer/custom.sh"');
    $sendPosition = strpos($script, 'curl -s --max-time 3 -X POST "$URL"');

    expect($customShPosition)->toBeLessThan($sendPosition);
});
