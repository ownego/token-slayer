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

it('stamps the client version (semver) into the script, UA, and version file', function () {
    $version = config('token_slayer.client_version');
    $script = $this->get(route('install-script'))->content();

    expect($version)->toBe('1.0.0')
        ->and($script)->toContain("CLIENT_VERSION='1.0.0'")
        ->and($script)->toContain('token-slayer-hook/1.0.0')
        ->and($script)->toContain("LATEST='1.0.0'");
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

it('sends an explicit User-Agent on every Anthropic curl call', function () {
    $script = $this->get('/install')->getContent();

    expect($script)->toContain("HOOK_UA='token-slayer-hook/");
    // Both the beacon and the profile lookup must carry it.
    expect(substr_count($script, '-A "$HOOK_UA"'))->toBeGreaterThanOrEqual(2);
});

it('retries a transient beacon error after a short self-heal window, not an hour', function () {
    $script = $this->get('/install')->getContent();

    expect($script)->toContain('BEACON_ERROR_RETRY_SECS=300');
    expect($script)->toContain('-le "$BEACON_ERROR_RETRY_SECS"');
    expect($script)->not->toContain('-le 3600');
});

it('forces bash for Claude Code hooks so Windows uses Git Bash deterministically', function () {
    $script = $this->get('/install')->getContent();

    // The Python merge appends the command dict with an explicit shell.
    expect($script)->toContain('"type": "command", "command": cmd, "shell": "bash"');
});

it('consults an account identity provider before the beacon, by-session then active', function () {
    $script = $this->get('/install')->getContent();

    expect($script)->toContain('provider_account()');
    expect($script)->toContain('CLAUDE_ACCOUNT_PROVIDER');
    expect($script)->toContain('account-provider/sessions/$SESSION_ID.json');
    expect($script)->toContain('account-provider/active.json');
    expect($script)->toContain('ACC_SOURCE="provider"');

    // provider runs before the credential beacon
    expect(strpos($script, 'provider_account && return'))
        ->toBeLessThan(strpos($script, 'OAUTH_TOKEN=$(current_access_token)'));

    // session id is extracted from the payload before resolve_account runs
    expect($script)->toContain('.session_id // .sessionId // ""');
    expect(strpos($script, 'SESSION_ID=$(printf'))
        ->toBeLessThan(strpos($script, "\n  resolve_account\n"));
});

it('bundles a detector-config and scans a proxy log by session id before giving up', function () {
    $script = $this->get('/install')->getContent();

    // Bundled config is written by the installer.
    expect($script)->toContain('detector-config.json');
    expect($script)->toContain('"teamclaude"');
    expect($script)->toContain('"join": "session"');

    // Generic scanner exists and runs inside the proxy branch, before NULL.
    expect($script)->toContain('detector_scan()');
    expect($script)->toContain('ACC_SOURCE="detector"');
    expect(strpos($script, 'detector_scan && return'))
        ->toBeLessThan(strpos($script, 'ACC_SOURCE="proxy"'));
});

it('attributes a ts_tokens window only when exactly one account served it', function () {
    $script = $this->get('/install')->getContent();

    expect($script)->toContain('DETECTOR_WINDOW_SECS=120');
    // Distinct-account gate: 1 -> attribute, else NULL (the SAFE rule).
    expect($script)->toContain('unique');
    expect($script)->toContain('if length == 1');
    // ts_tokens arm resolves to the detector source, not a guess.
    expect(strpos($script, 'DETECTOR_WINDOW_SECS'))
        ->toBeGreaterThan(strpos($script, 'detector_scan()'));
});

it('reserves the exclude-check hook point between attribution and the POST', function () {
    $script = $this->get('/install')->getContent();

    expect($script)->toContain('exclude-check hook point (Phase 3)');

    $marker = strpos($script, 'exclude-check hook point (Phase 3)');
    expect($marker)->toBeGreaterThan(strpos($script, 'resolve_account'));
    expect($marker)->toBeLessThan(strpos($script, 'curl -s --max-time 3 -X POST'));
});

it('sets up a python venv and installs slayer-cli, with a shim that execs the venv module', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('-m venv')
        ->toContain('/venv/bin/pip')
        ->toContain('-m slayer_cli')
        ->toContain('exec env SLAYER_NS')
        ->toContain('SLAYER_NS=token_slayer')
        ->toContain('SLAYER_INSTALL_URL=');
});

it('registers the current Claude login as a base account slot after installing the CLI', function () {
    $script = $this->get(route('install-script'))->content();

    // Best-effort, namespaced, never blocks the install.
    expect($script)
        ->toContain('-m slayer_cli detect-base')
        ->toContain('SLAYER_NS=token_slayer');

    // It must run AFTER the shim is written (needs the venv/package present).
    $shimPos = strpos($script, 'chmod +x "$HOME/.local/bin/token-slayer"');
    $detectPos = strpos($script, '-m slayer_cli detect-base');
    expect($shimPos)->not->toBeFalse()
        ->and($detectPos)->not->toBeFalse()
        ->and($shimPos)->toBeLessThan($detectPos);
});

it('registers an always-on Stop hook that warms the local usage cache, independent of auto-switch', function () {
    $script = $this->get(route('install-script'))->content();

    // Invokes the venv directly with an explicit namespace, like detect-base
    // -- NOT the shared `$HOME/.local/bin/token-slayer` shim, whose baked-in
    // namespace is whichever install ran last (would silently refresh the
    // wrong namespace on a machine with more than one installed).
    expect($script)
        ->toContain('-m slayer_cli hook usage-refresh')
        ->toContain('SLAYER_NS=token_slayer "$HOME/.config/token_slayer/venv/bin/python"')
        ->toContain('HOOK_FINGERPRINT="token_slayer/venv/bin/python"')
        ->toContain('events = ["Stop"]');

    // Appended alongside send-hook.sh's own Stop entry, never replacing it.
    expect($script)->not->toContain('data["hooks"][event] = [{');

    // The dedup filter is a plain substring match (`fingerprint not in
    // json.dumps(e)`) -- a fingerprint that isn't literally contained in
    // the command it's meant to identify silently fails to replace a stale
    // entry on re-install, leaving duplicates forever.
    $fingerprintPos = strpos($script, 'HOOK_FINGERPRINT="token_slayer/venv/bin/python"');
    $fingerprint = 'token_slayer/venv/bin/python';
    expect($fingerprintPos)->not->toBeFalse()
        ->and(str_contains($script, 'SLAYER_NS=token_slayer "$HOME/.config/'.$fingerprint.'"'))->toBeTrue();

    // Must be registered AFTER the shim exists (this section runs after it).
    $shimPos = strpos($script, 'chmod +x "$HOME/.local/bin/token-slayer"');
    $refreshPos = strpos($script, 'hook usage-refresh');
    expect($shimPos)->not->toBeFalse()
        ->and($refreshPos)->not->toBeFalse()
        ->and($shimPos)->toBeLessThan($refreshPos);
});

it('registers an always-on SessionStart hook that tracks the session for the Sessions TUI page, independent of auto-switch', function () {
    $script = $this->get(route('install-script'))->content();

    // Same shape as the usage-refresh Stop hook above: invokes the venv
    // directly with an explicit namespace (never the shared shim), so a
    // machine with more than one namespace installed never tracks the
    // wrong one.
    expect($script)
        ->toContain('-m slayer_cli hook session-track-start')
        ->toContain('SLAYER_NS=token_slayer')
        ->toContain('HOOK_FINGERPRINT="hook session-track-start"')
        ->toContain('events = ["SessionStart"]');

    // Appended alongside send-hook.sh's own SessionStart entry, never
    // replacing it -- must not be a full-replace assignment.
    expect($script)->not->toContain('data["hooks"][event] = [{');

    // The dedup filter is a plain substring match against `json.dumps(e)`
    // of the WHOLE settings entry -- the fingerprint must be a literal,
    // contiguous substring of the actual command text, or re-install
    // silently leaves duplicates forever instead of replacing the stale
    // entry (caught live: an earlier fingerprint here concatenated the
    // namespace with "/hook session-track-start", which is never actually
    // adjacent in the real command and re-running the merge twice produced
    // 2 entries instead of 1 -- verified by extracting this exact snippet
    // and executing it against fixture settings.json files).
    $cmdPos = strpos($script, 'SESSION_TRACK_CMD="SLAYER_NS=token_slayer');
    $fingerprintDeclPos = strpos($script, 'HOOK_FINGERPRINT="hook session-track-start"');
    expect($cmdPos)->not->toBeFalse()->and($fingerprintDeclPos)->not->toBeFalse();
    $cmdLine = substr($script, $cmdPos, $fingerprintDeclPos - $cmdPos);
    expect($cmdLine)->toContain('hook session-track-start');

    // Must be registered AFTER the shim exists (this section runs after it).
    $shimPos = strpos($script, 'chmod +x "$HOME/.local/bin/token-slayer"');
    $trackPos = strpos($script, 'hook session-track-start');
    expect($shimPos)->not->toBeFalse()
        ->and($trackPos)->not->toBeFalse()
        ->and($shimPos)->toBeLessThan($trackPos);
});

it('downloads the wheel to a PEP 427-valid temp name before pip-installing (pip rejects slayer_cli-latest.whl)', function () {
    $script = $this->get(route('install-script'))->content();

    // pip refuses `pip install <url ending in slayer_cli-latest.whl>` because
    // `latest` is not a valid version segment; the script must download first
    // to a spec-valid filename, then install that local file.
    expect($script)
        ->toContain('slayer_cli-0.0.0-py3-none-any.whl')
        ->toContain('install --quiet "$SLAYER_WHL"');

    // The served wheel version may be unchanged between builds, so a plain
    // --upgrade would ship stale code; the package code is force-reinstalled.
    expect($script)->toContain('install --quiet --force-reinstall --no-deps "$SLAYER_WHL"');

    // It must NOT pip-install straight from the wheel URL/route anymore.
    expect($script)->not->toContain('pip" install --quiet --upgrade "'.route('slayer-wheel').'"');
});

it('does not let a malformed existing settings.json abort the whole installer', function () {
    $script = $this->get(route('install-script'))->content();

    // The settings.json merge runs under `set -e`; a bare json.load() on a
    // corrupt file would crash the entire install. It must catch that, back the
    // bad file up, and continue.
    expect($script)
        ->toContain('except (ValueError, OSError):')
        ->toContain('.corrupt-bak')
        ->toContain('was invalid JSON');
});

it('falls back to the old update/status behavior when the venv is missing, and never blocks on a python failure', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('already up to date')
        ->toContain('usage: token-slayer {update|status}')
        ->toContain('venv setup skipped')
        ->toContain('optional install skipped');

    $execPosition = strpos($script, 'exec env SLAYER_NS');
    $fallbackPosition = strpos($script, 'already up to date');

    expect($execPosition)->not->toBeFalse()
        ->and($fallbackPosition)->not->toBeFalse()
        ->and($execPosition)->toBeLessThan($fallbackPosition);
});

it('symlinks slayer to the token-slayer shim', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)->toContain('ln -sf "$HOME/.local/bin/token-slayer" "$HOME/.local/bin/slayer"');
});

it('redirects the slayer-cli wheel route to the configured release asset URL', function () {
    config(['token_slayer.slayer_cli_wheel_url' => 'https://example.com/slayer_cli-latest.whl']);

    $response = $this->get('/dist/slayer_cli-latest.whl');

    $response->assertRedirect('https://example.com/slayer_cli-latest.whl');
});

it('404s the slayer-cli wheel route when no release URL is configured', function () {
    config(['token_slayer.slayer_cli_wheel_url' => '']);

    $response = $this->get('/dist/slayer_cli-latest.whl');

    $response->assertNotFound();
});
