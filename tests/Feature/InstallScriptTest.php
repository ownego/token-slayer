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

it('stores a sha256 checksum of send-hook.sh after writing it', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('CHECKSUM_FILE="$HOME/.config/token_slayer/.hook-checksum"')
        ->toContain('sha256 < "$HELPER" > "$CHECKSUM_FILE"');
});

it('compares the existing send-hook.sh against the stored checksum before overwriting', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('if [ -f "$HELPER" ]')
        ->toContain('OLD_SHA=$(sha256 < "$HELPER")')
        ->toContain('STORED_SHA=$(cat "$CHECKSUM_FILE")')
        ->toContain('[ -z "$STORED_SHA" ] || [ "$OLD_SHA" != "$STORED_SHA" ]');

    $compareBlockPosition = strpos($script, 'if [ -f "$HELPER" ]');
    $overwritePosition = strpos($script, "cat > \"\$HELPER\" <<'HOOK_SH'");

    expect($compareBlockPosition)->toBeLessThan($overwritePosition);
});

it('backs up a hand-modified send-hook.sh before overwriting it', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('HOOK_BACKUP="$HELPER.bak.$(date +%Y%m%d%H%M%S)"')
        ->toContain('cp "$HELPER" "$HOOK_BACKUP"');

    $backupPosition = strpos($script, 'HOOK_BACKUP="$HELPER.bak.$(date +%Y%m%d%H%M%S)"');
    $overwritePosition = strpos($script, "cat > \"\$HELPER\" <<'HOOK_SH'");

    expect($backupPosition)->toBeLessThan($overwritePosition);
});

it('warns loudly and points to custom.sh when a hand-modified hook was backed up', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('if [ -n "$HOOK_BACKUP" ]')
        ->toContain('WARNING')
        ->toContain('backup saved to: $HOOK_BACKUP')
        ->toContain('~/.config/token_slayer/custom.sh')
        ->toContain('survives every update');
});

it('reports custom.sh and hook modification status in the token-slayer CLI', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('custom.sh: active')
        ->toContain('custom.sh: none')
        ->toContain('send-hook.sh: stock')
        ->toContain('send-hook.sh: modified')
        ->toContain('send-hook.sh: unknown');
});

it('prunes old send-hook.sh backups to the newest 3', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('ls -1t "$HOME/.config/token_slayer"/send-hook.sh.bak.* 2>/dev/null | tail -n +4 | xargs rm -f --');
});

it('skips account resolution entirely for non-Claude providers', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)->toContain('[ -n "${PROVIDER:-}" ]');

    $guardPosition = strpos($script, '[ -n "${PROVIDER:-}" ]');
    $resolveDefinitionPosition = strpos($script, 'resolve_account() {');

    expect($guardPosition)->not->toBeFalse()
        ->and($resolveDefinitionPosition)->not->toBeFalse()
        ->and($guardPosition)->toBeGreaterThan($resolveDefinitionPosition);
});

it('sends an org-id beacon request that costs zero tokens and never touches quota', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('"max_tokens":0')
        ->toContain('https://api.anthropic.com/v1/messages')
        ->toContain('claude-haiku-4-5-20251001');
});

it('parses the anthropic-organization-id response header from the beacon', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('anthropic-organization-id')
        ->toContain("grep -i '^anthropic-organization-id:'");
});

it('uses the x-api-key header for the beacon when only an API key is available', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)->toContain('ANTHROPIC_API_KEY')
        ->and($script)->toContain('x-api-key');
});

it('negatively caches identity lookups so repeat events skip the network', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('restricted')
        ->toContain('identity-cache.json')
        ->toContain('checked_at');
});

it('merges account_org_id into the outgoing event body when resolved', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)->toContain('account_org_id');

    $bodyAssignPosition = strpos($script, 'BODY=$(printf \'%s\' "$BODY" | jq -c --arg e "$ACC_EMAIL"');
    expect($bodyAssignPosition)->not->toBeFalse();

    $mergeBlock = substr($script, $bodyAssignPosition, 700);
    expect($mergeBlock)->toContain('account_org_id');
});

it('bumps the client version to 3 for the org-id beacon rollout', function () {
    $script = $this->get(route('install-script'))->content();

    expect(config('token_slayer.client_version'))->toBe('3')
        ->and($script)->toContain("CLIENT_VERSION='3'");
});

it('tips users toward custom.sh to customize what their fighter shows, at the end of a successful install', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('~/.config/token_slayer/custom.sh')
        ->toContain('customize what your fighter shows')
        ->toContain('survives every update');

    $tipPosition = strpos($script, 'customize what your fighter shows');
    $lastHookInstallPosition = strpos($script, 'installed Antigravity CLI hooks');

    expect($tipPosition)->not->toBeFalse()
        ->and($lastHookInstallPosition)->not->toBeFalse()
        ->and($tipPosition)->toBeGreaterThan($lastHookInstallPosition);
});
