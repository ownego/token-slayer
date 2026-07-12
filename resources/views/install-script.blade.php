@php
    $envVar = strtoupper($namespace).'_TOKEN';
    $envCheck = '${'.$envVar.':-}';
    $envRead = '"$'.$envVar.'"';
@endphp
{!! '#!/bin/sh' !!}
# {{ $namespace }} hook installer
# Installs Claude Code, Codex, and Antigravity CLI hooks that POST to {{ $baseUrl }}.
# Hooks read the token at runtime from ~/.config/{{ $namespace }}/token.
#
# Pass {{ $envVar }}=<token> in the environment to save the token in the
# same run, e.g. `curl -fsSL ... | {{ $envVar }}=xxx sh`. Otherwise the
# token must be written separately.
#
# Re-running this script is safe — existing {{ $namespace }} hook blocks are
# replaced, other settings in your config files are preserved.

set -e

PY=$(command -v python3 || command -v python || true)
if [ -z "$PY" ]; then
    echo "error: python3 (or python) is required to merge ~/.claude/settings.json" >&2
    exit 1
fi

# Ensure token directory exists.
mkdir -p "$HOME/.config/{{ $namespace }}"

# Bundled detector config (data, not code): tells the hook where a locally-run
# proxy already logs session/account, so token-slayer can attribute without
# modifying the proxy. Overwritten on every install so new entries ship centrally.
cat > "$HOME/.config/{{ $namespace }}/detector-config.json" <<'DETECTOR_JSON'
{
  "teamclaude": { "join": "session", "logs": ["~/.config/teamclaude/requests/*.log"], "account_pattern": "account: ([^[:space:]]+)" },
  "claudehub":  { "join": "ts_tokens", "logs": ["~/.config/claudehub/stats.jsonl"], "account_field": "account_name", "ts_field": "ts" },
  "auth2api":   { "join": "ts_tokens", "logs": ["~/.config/auth2api/stats.jsonl"], "account_field": "accountEmail", "ts_field": "ts" }
}
DETECTOR_JSON

# Drop the hook helper script. Stop events are enriched with a tokens count
# parsed from the local Claude transcript (requires jq when available),
# because the server cannot read the user's filesystem.
HELPER="$HOME/.config/{{ $namespace }}/send-hook.sh"
CHECKSUM_FILE="$HOME/.config/{{ $namespace }}/.hook-checksum"

sha256() { if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -d' ' -f1; else shasum -a 256 | cut -d' ' -f1; fi; }

# If an existing send-hook.sh no longer matches the checksum of the last
# stock install (or predates checksum tracking entirely), assume the user
# hand-edited it and back it up before we overwrite it below.
HOOK_BACKUP=""
if [ -f "$HELPER" ]; then
    OLD_SHA=$(sha256 < "$HELPER")
    STORED_SHA=""
    [ -r "$CHECKSUM_FILE" ] && STORED_SHA=$(cat "$CHECKSUM_FILE")
    if [ -z "$STORED_SHA" ] || [ "$OLD_SHA" != "$STORED_SHA" ]; then
        HOOK_BACKUP="$HELPER.bak.$(date +%Y%m%d%H%M%S)"
        cp "$HELPER" "$HOOK_BACKUP"
    fi
fi

cat > "$HELPER" <<'HOOK_SH'
#!/usr/bin/env bash
set -u

API_URL='{{ $baseUrl }}'
TOKEN_FILE="$HOME/.config/{{ $namespace }}/token"

BODY=$(cat)
[ -r "$TOKEN_FILE" ] || exit 0

if command -v jq >/dev/null 2>&1; then
  TRANSCRIPT=$(printf '%s' "$BODY" | jq -r '.transcript_path // .transcriptPath // ""' 2>/dev/null)
  if [ -n "$TRANSCRIPT" ] && [ -r "$TRANSCRIPT" ]; then
    TOKENS=$(jq -sr '
      . as $a
      | (length - 1) as $end
      | reduce range($end; -1; -1) as $i ({t:0, stop:false};
          if .stop then . else
            ($a[$i]) as $e
            | if $e.type == "assistant" or $e.type == "PLANNER_RESPONSE" or $e.source == "MODEL" then
                .t += ($e.message.usage.output_tokens // $e.usage.output_tokens // $e.usage.outputTokens // 0)
              elif ($e.type == "USER_INPUT" or $e.source == "USER_EXPLICIT") then
                .stop = true
              elif $e.type == "user"
                   and ((try $e.message.content[0].type catch null) != "tool_result") then
                .stop = true
              else . end
          end)
      | .t
    ' "$TRANSCRIPT" 2>/dev/null)
    if [ -n "${TOKENS:-}" ]; then
      BODY=$(printf '%s' "$BODY" | jq -c --argjson t "$TOKENS" '. + {tokens:$t}' 2>/dev/null || printf '%s' "$BODY")
    fi
  fi
fi

URL="$API_URL"
if [ "${PROVIDER:-}" = "codex" ]; then
  URL="${URL}?provider=codex"
elif [ "${PROVIDER:-}" = "antigravity" ]; then
  URL="${URL}?provider=antigravity"
fi

CLIENT_VERSION='{{ $clientVersion }}'
HOOK_UA='token-slayer-hook/{{ $clientVersion }} (external, cli)'
NS_DIR="$HOME/.config/{{ $namespace }}"

sha256() { if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -d' ' -f1; else shasum -a 256 | cut -d' ' -f1; fi; }

current_access_token() {
  # Same lookup order Claude Code uses; hooks inherit CLAUDE_CONFIG_DIR.
  # CLAUDE_CODE_OAUTH_TOKEN (CI/automation) takes priority over on-disk credentials.
  if [ -n "${CLAUDE_CODE_OAUTH_TOKEN:-}" ]; then
    printf '%s' "$CLAUDE_CODE_OAUTH_TOKEN"
    return
  fi
  for f in "${CLAUDE_CONFIG_DIR:-}/.credentials.json" "$HOME/.claude/.credentials.json"; do
    [ -r "$f" ] || continue
    jq -r '.claudeAiOauth.accessToken // ""' "$f" 2>/dev/null && return
  done
  if [ "$(uname)" = "Darwin" ]; then
    security find-generic-password -s "Claude Code-credentials" -w 2>/dev/null \
      | jq -r '.claudeAiOauth.accessToken // ""' 2>/dev/null
  fi
}

beacon_org_id() {
  # $1 = full auth header value, e.g. "Authorization: Bearer xxx" or "x-api-key: xxx".
  # Deliberately-invalid inference request: max_tokens=0 and empty messages -> HTTP
  # 400, zero token cost, touches no quota, and works with bare inference scope
  # (including setup-tokens, which get a permanent 403 from /api/oauth/profile). The
  # response headers still carry the org UUID that owns the token.
  curl -si --max-time 5 -A "$HOOK_UA" "https://api.anthropic.com/v1/messages" \
    -H "$1" \
    -H "anthropic-version: 2023-06-01" -H "content-type: application/json" \
    -d '{"model":"claude-haiku-4-5-20251001","max_tokens":0,"messages":[]}' 2>/dev/null \
    | grep -i '^anthropic-organization-id:' | awk '{print $2}' | tr -d '\r'
}

provider_account() {
  # Account Identity Provider intake. Whichever layer holds the real credential
  # (a proxy or switcher) may declare identity via a portable transport;
  # token-slayer ships no code into that layer -- it only reads. Env var + file
  # only (socket / URL / executable transports are out of scope for now).
  _pv=""
  if [ -n "${CLAUDE_ACCOUNT_PROVIDER:-}" ] && [ -r "${CLAUDE_ACCOUNT_PROVIDER}" ]; then
    _pv="${CLAUDE_ACCOUNT_PROVIDER}"
  elif [ -n "${SESSION_ID:-}" ] && [ -r "$NS_DIR/account-provider/sessions/$SESSION_ID.json" ]; then
    _pv="$NS_DIR/account-provider/sessions/$SESSION_ID.json"
  elif [ -r "$NS_DIR/account-provider/active.json" ]; then
    _pv="$NS_DIR/account-provider/active.json"
  fi
  [ -n "$_pv" ] || return 1

  _org=$(jq -r '.org_uuid // ""' "$_pv" 2>/dev/null)
  [ -n "$_org" ] || return 1
  ACC_ORG_ID="$_org"
  ACC_EMAIL=$(jq -r '.email // ""' "$_pv" 2>/dev/null)
  ACC_UUID=$(jq -r '.uuid // ""' "$_pv" 2>/dev/null)
  ACC_SOURCE="provider"
  return 0
}

detector_scan() {
  # Generic, data-driven fallback for local proxies. Reads ONLY existing local
  # logs named by detector-config.json; never writes/rotates/deletes them.
  # Two join strategies per config entry: exact `session` (match the current
  # session id) and best-effort `ts_tokens` (SAFE single-account time window).
  _cfg="$NS_DIR/detector-config.json"
  [ -r "$_cfg" ] || return 1

  for _mgr in $(jq -r 'keys[]' "$_cfg" 2>/dev/null); do
    _join=$(jq -r --arg k "$_mgr" '.[$k].join // ""' "$_cfg" 2>/dev/null)
    case "$_join" in
      session)
        [ -n "${SESSION_ID:-}" ] || continue
        _pat=$(jq -r --arg k "$_mgr" '.[$k].account_pattern // ""' "$_cfg" 2>/dev/null)
        [ -n "$_pat" ] || continue
        for _glob in $(jq -r --arg k "$_mgr" '.[$k].logs[]' "$_cfg" 2>/dev/null); do
          _glob=$(printf '%s' "$_glob" | sed "s#^~#$HOME#")
          for _f in $_glob; do
            [ -r "$_f" ] || continue
            grep -qF -- "$SESSION_ID" "$_f" 2>/dev/null || continue
            # This file carries the current session (teamclaude logs one file per
            # request), so we trust its first "account:" match. account_pattern must
            # hold exactly one capture group and no "/" (the sed delimiter). Unlike
            # the ts_tokens arm this has no distinct-account guard: it rests on the
            # one-request-per-file assumption -- verify on staging with a real log.
            _acct=$(sed -nE "s/.*$_pat.*/\1/p" "$_f" 2>/dev/null | head -1)
            if [ -n "$_acct" ]; then
              ACC_EMAIL="$_acct"; ACC_UUID=""; ACC_ORG_ID=""; ACC_SOURCE="detector"
              return 0
            fi
          done
        done
        ;;
      ts_tokens)
        DETECTOR_WINDOW_SECS=120
        _af=$(jq -r --arg k "$_mgr" '.[$k].account_field // ""' "$_cfg" 2>/dev/null)
        _tf=$(jq -r --arg k "$_mgr" '.[$k].ts_field // "ts"' "$_cfg" 2>/dev/null)
        [ -n "$_af" ] || continue
        _now=$(date +%s)
        _lo=$((_now - DETECTOR_WINDOW_SECS))
        for _glob in $(jq -r --arg k "$_mgr" '.[$k].logs[]' "$_cfg" 2>/dev/null); do
          _glob=$(printf '%s' "$_glob" | sed "s#^~#$HOME#")
          for _f in $_glob; do
            [ -r "$_f" ] || continue
            # SAFE rule: one distinct account in the window -> attribute; more -> NULL.
            _acct=$(jq -rs --arg af "$_af" --arg tf "$_tf" \
              --argjson lo "$_lo" --argjson hi "$_now" '
                [ .[] | select((.[$tf] // 0) >= $lo and (.[$tf] // 0) <= $hi) | .[$af] ]
                | map(select(. != null and . != "")) | unique
                | if length == 1 then .[0] else "" end' "$_f" 2>/dev/null)
            if [ -n "$_acct" ]; then
              ACC_EMAIL="$_acct"; ACC_UUID=""; ACC_ORG_ID=""; ACC_SOURCE="detector"
              return 0
            fi
          done
        done
        ;;
    esac
  done
  return 1
}

resolve_account() {
  ACC_EMAIL="" ACC_UUID="" ACC_SOURCE="" ACC_ORG_ID=""

  # Pre-check: non-Claude providers (codex/antigravity) never carry Claude account claims.
  [ -n "${PROVIDER:-}" ] && return

  # 0. Account Identity Provider (proxy/switcher declares identity) -- highest signal.
  provider_account && return

  # 1. Manual override: wins over credential/proxy/auto (a provider still precedes it).
  if [ -r "$NS_DIR/account.json" ]; then
    ACC_EMAIL=$(jq -r '.email // ""' "$NS_DIR/account.json" 2>/dev/null)
    ACC_UUID=$(jq -r '.uuid // ""' "$NS_DIR/account.json" 2>/dev/null)
    [ -n "$ACC_EMAIL" ] && { ACC_SOURCE="manual"; return; }
  fi

  # 2. Proxy detect: base URL rerouted -> client cannot know the account. Don't guess,
  #    and don't beacon a URL that isn't api.anthropic.com.
  case "${ANTHROPIC_BASE_URL:-}" in
    ""|*api.anthropic.com*) ;;
    *) detector_scan && return
       ACC_SOURCE="proxy"; ACC_EMAIL=""; ACC_UUID=""; return ;;
  esac

  # 3. Credential identity: resolve the org UUID via a zero-cost beacon call, cached
  #    per token fingerprint so repeat events do zero network work.
  OAUTH_TOKEN=$(current_access_token)
  if [ -n "$OAUTH_TOKEN" ]; then
    TOKEN="$OAUTH_TOKEN"
    AUTH_HEADER="Authorization: Bearer $OAUTH_TOKEN"
  elif [ -n "${ANTHROPIC_API_KEY:-}" ]; then
    TOKEN="$ANTHROPIC_API_KEY"
    AUTH_HEADER="x-api-key: $ANTHROPIC_API_KEY"
  else
    TOKEN=""
  fi

  if [ -n "$TOKEN" ]; then
    FP=$(printf '%s' "$TOKEN" | sha256)
    CACHE="$NS_DIR/identity-cache.json"
    NOW=$(date +%s)
    BEACON_ERROR_RETRY_SECS=300

    CACHED_STATUS="" CACHED_CHECKED_AT=0
    if [ -r "$CACHE" ]; then
      CACHED_STATUS=$(jq -r --arg fp "$FP" '.[$fp].status // ""' "$CACHE" 2>/dev/null)
      CACHED_CHECKED_AT=$(jq -r --arg fp "$FP" '.[$fp].checked_at // 0' "$CACHE" 2>/dev/null)
    fi
    : "${CACHED_CHECKED_AT:=0}"

    SHOULD_LOOKUP=1
    case "$CACHED_STATUS" in
      ok)
        ACC_ORG_ID=$(jq -r --arg fp "$FP" '.[$fp].org_id // ""' "$CACHE" 2>/dev/null)
        ACC_EMAIL=$(jq -r --arg fp "$FP" '.[$fp].email // ""' "$CACHE" 2>/dev/null)
        ACC_UUID=$(jq -r --arg fp "$FP" '.[$fp].uuid // ""' "$CACHE" 2>/dev/null)
        SHOULD_LOOKUP=0
        ;;
      restricted)
        # Permanent negative for this fp: never beacon it again.
        SHOULD_LOOKUP=0
        ;;
      error)
        # Transient failure: retry only after the short self-heal window.
        [ $((NOW - CACHED_CHECKED_AT)) -le "$BEACON_ERROR_RETRY_SECS" ] && SHOULD_LOOKUP=0
        ;;
    esac

    if [ "$SHOULD_LOOKUP" = "1" ]; then
      ACC_ORG_ID=$(beacon_org_id "$AUTH_HEADER")

      if [ -n "$ACC_ORG_ID" ]; then
        STATUS="ok"
        if [ -n "$OAUTH_TOKEN" ]; then
          # Best-effort profile lookup for email/uuid (enables server auto-learn); a
          # 403 here is fine and just leaves email/uuid blank -- the beacon already
          # proved identity via the org id.
          PROFILE=$(curl -sf --max-time 5 -A "$HOOK_UA" "https://api.anthropic.com/api/oauth/profile" \
            -H "Authorization: Bearer $OAUTH_TOKEN" -H "anthropic-beta: oauth-2025-04-20" 2>/dev/null)
          ACC_EMAIL=$(printf '%s' "$PROFILE" | jq -r '.account.email // .account.email_address // .email // ""' 2>/dev/null)
          ACC_UUID=$(printf '%s' "$PROFILE" | jq -r '.account.uuid // .account_uuid // ""' 2>/dev/null)
        fi
      else
        STATUS="error"
      fi

      TMP=$(mktemp) && jq --arg fp "$FP" --arg o "$ACC_ORG_ID" --arg e "$ACC_EMAIL" \
        --arg u "$ACC_UUID" --arg st "$STATUS" --argjson t "$NOW" \
        '. + {($fp): {org_id: $o, email: $e, uuid: $u, status: $st, checked_at: $t}}' \
        "$CACHE" 2>/dev/null > "$TMP" \
        || printf '{"%s":{"org_id":"%s","email":"%s","uuid":"%s","status":"%s","checked_at":%s}}' \
             "$FP" "$ACC_ORG_ID" "$ACC_EMAIL" "$ACC_UUID" "$STATUS" "$NOW" > "$TMP"
      mv "$TMP" "$CACHE"
    fi

    [ -n "$ACC_ORG_ID" ] && { ACC_SOURCE="credential"; return; }
  fi

  # 4. Fallback: oauthAccount (may be stale under external switchers).
  CJ="${CLAUDE_CONFIG_DIR:-$HOME}/.claude.json"
  [ -r "$CJ" ] || CJ="$HOME/.claude.json"
  if [ -r "$CJ" ]; then
    ACC_EMAIL=$(jq -r '.oauthAccount.emailAddress // ""' "$CJ" 2>/dev/null)
    ACC_UUID=$(jq -r '.oauthAccount.accountUuid // ""' "$CJ" 2>/dev/null)
    [ -n "$ACC_EMAIL" ] && ACC_SOURCE="auto"
  fi
}

if command -v jq >/dev/null 2>&1; then
  SESSION_ID=$(printf '%s' "$BODY" | jq -r '.session_id // .sessionId // ""' 2>/dev/null)
  resolve_account
  BODY=$(printf '%s' "$BODY" | jq -c --arg e "$ACC_EMAIL" --arg u "$ACC_UUID" \
    --arg s "$ACC_SOURCE" --arg v "$CLIENT_VERSION" --arg o "$ACC_ORG_ID" \
    '. + {client_version: $v} + (if $s != "" then {account_source: $s} else {} end)
       + (if $e != "" then {account_email: $e, account_uuid: $u} else {} end)
       + (if $o != "" then {account_org_id: $o} else {} end)' \
    2>/dev/null || printf '%s' "$BODY")
fi

CUSTOM_SH="$HOME/.config/{{ $namespace }}/custom.sh"
[ -r "$CUSTOM_SH" ] && . "$CUSTOM_SH"

# --- exclude-check hook point (Phase 3) ---
# Reserved: a future dev-owned exclude-accounts.json will let a developer drop
# their own private accounts here (exit 0 before POST) so those events never
# leave the machine. Not active yet -- default is track everything.

curl -s --max-time 3 -X POST "$URL" \
  -H "Authorization: Bearer $(cat "$TOKEN_FILE")" \
  -H 'Content-Type: application/json' \
  -d "$BODY" >/dev/null 2>&1 &
HOOK_SH
chmod +x "$HELPER"

sha256 < "$HELPER" > "$CHECKSUM_FILE"

# Keep only the 3 most recent backups so a long-lived install doesn't
# accumulate one file per update.
ls -1t "$HOME/.config/{{ $namespace }}"/send-hook.sh.bak.* 2>/dev/null | tail -n +4 | xargs rm -f --

if [ -n "$HOOK_BACKUP" ]; then
    echo ""
    echo "=========================================================="
    echo "WARNING: your existing send-hook.sh had local modifications"
    echo "and has been overwritten by this install."
    echo ""
    echo "  backup saved to: $HOOK_BACKUP"
    echo ""
    echo "Move your customizations into:"
    echo "  ~/.config/{{ $namespace }}/custom.sh"
    echo "That file is sourced automatically on every hook run and"
    echo "survives every update -- edits to send-hook.sh itself do not."
    echo "=========================================================="
    echo ""
fi

printf '%s' "{{ $clientVersion }}" > "$HOME/.config/{{ $namespace }}/version"

mkdir -p "$HOME/.local/bin"
cat > "$HOME/.local/bin/token-slayer" <<'CLI_SH'
#!/usr/bin/env bash
set -u
NS_DIR="$HOME/.config/{{ $namespace }}"
INSTALL_URL='{{ $installUrl }}'
LATEST='{{ $clientVersion }}'

sha256() { if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -d' ' -f1; else shasum -a 256 | cut -d' ' -f1; fi; }

case "${1:-}" in
  update)
    CURRENT=$(cat "$NS_DIR/version" 2>/dev/null || echo "?")
    if [ "$CURRENT" = "$LATEST" ]; then echo "token-slayer: already up to date (v$CURRENT)"; exit 0; fi
    echo "token-slayer: v$CURRENT -> v$LATEST, re-running installer..."
    curl -fsSL "$INSTALL_URL" | sh
    ;;
  status)
    echo "client version: $(cat "$NS_DIR/version" 2>/dev/null || echo none) (latest known at install: $LATEST)"
    [ -s "$NS_DIR/token" ] && echo "hook token: present" || echo "hook token: MISSING"
    if [ -r "$NS_DIR/account.json" ]; then
      echo "account: $(jq -r '.email' "$NS_DIR/account.json" 2>/dev/null) (manual)"
    else
      echo "account: resolved automatically per event (credential/auto)"
    fi
    if [ -r "$NS_DIR/custom.sh" ]; then
      echo "custom.sh: active"
    else
      echo "custom.sh: none"
    fi
    if [ -r "$NS_DIR/.hook-checksum" ]; then
      CURRENT_SHA=$(sha256 < "$NS_DIR/send-hook.sh" 2>/dev/null)
      STORED_SHA=$(cat "$NS_DIR/.hook-checksum")
      if [ "$CURRENT_SHA" = "$STORED_SHA" ]; then
        echo "send-hook.sh: stock"
      else
        echo "send-hook.sh: modified"
      fi
    else
      echo "send-hook.sh: unknown"
    fi
    ;;
  *) echo "usage: token-slayer {update|status}"; exit 1 ;;
esac
CLI_SH
chmod +x "$HOME/.local/bin/token-slayer"

case ":$PATH:" in
  *":$HOME/.local/bin:"*) ;;
  *) for rc in "$HOME/.zshrc" "$HOME/.bashrc"; do
       [ -f "$rc" ] && ! grep -q '# token-slayer PATH' "$rc" \
         && printf '\n# token-slayer PATH\nexport PATH="$HOME/.local/bin:$PATH"\n' >> "$rc"
     done ;;
esac

CLAUDE_CMD="bash $HELPER"
CODEX_CMD="PROVIDER=codex bash $HELPER"
AGY_CMD="PROVIDER=antigravity bash $HELPER"

# If {{ $envVar }} was passed, save it now so a single command does both
# hook setup and token install.
if [ -n "{!! $envCheck !!}" ]; then
    TOKEN_FILE="$HOME/.config/{{ $namespace }}/token"
    printf '%s' {!! $envRead !!} > "$TOKEN_FILE"
    chmod 600 "$TOKEN_FILE"
    echo "saved token -> $TOKEN_FILE"
fi

# --- Claude Code: merge into ~/.claude/settings.json ---
mkdir -p "$HOME/.claude"
SETTINGS="$HOME/.claude/settings.json"
[ -s "$SETTINGS" ] || echo '{}' > "$SETTINGS"

CLAUDE_CMD="$CLAUDE_CMD" HOOK_FINGERPRINT="{{ $namespace }}/send-hook.sh" "$PY" - "$SETTINGS" <<'PY'
import json, os, sys

path = sys.argv[1]
cmd = os.environ["CLAUDE_CMD"]
events = [
    "SessionStart", "UserPromptSubmit", "PreToolUse", "PostToolUse",
    "Stop", "SubagentStop", "SessionEnd", "Notification",
]

with open(path) as f:
    data = json.load(f)

data.setdefault("hooks", {})
fingerprint = os.environ["HOOK_FINGERPRINT"]  # e.g. "{{ $namespace }}/send-hook.sh" not in json.dumps(e) filters out our own stale entries
for event in events:
    entries = [e for e in data["hooks"].get(event, [])
               if fingerprint not in json.dumps(e)]
    entries.append({"hooks": [{"type": "command", "command": cmd, "shell": "bash"}]})
    data["hooks"][event] = entries

with open(path, "w") as f:
    json.dump(data, f, indent=2)
    f.write("\n")
PY

echo "installed Claude Code hooks -> $SETTINGS"

# --- Codex CLI: rewrite the {{ $namespace }} block in ~/.codex/config.toml ---
mkdir -p "$HOME/.codex"
CODEX_CONFIG="$HOME/.codex/config.toml"
touch "$CODEX_CONFIG"

# Remove any previous {{ $namespace }} block (between markers) so we can append a fresh one.
NAMESPACE="{{ $namespace }}" "$PY" - "$CODEX_CONFIG" <<'PY'
import os, sys, re

path = sys.argv[1]
ns = re.escape(os.environ["NAMESPACE"])
with open(path) as f:
    text = f.read()

text = re.sub(
    rf"(?ms)^# >>> {ns} hooks\n.*?^# <<< {ns} hooks\n?",
    "",
    text,
)

with open(path, "w") as f:
    f.write(text)
PY

cat >> "$CODEX_CONFIG" <<EOF
# >>> {{ $namespace }} hooks
[[hooks]]
event = "session_start"
command = "$CODEX_CMD"

[[hooks]]
event = "stop"
command = "$CODEX_CMD"
# <<< {{ $namespace }} hooks
EOF

echo "installed Codex CLI hooks -> $CODEX_CONFIG"

# --- Antigravity CLI: merge into ~/.gemini/config/hooks.json ---
mkdir -p "$HOME/.gemini/config"
AGY_HOOKS="$HOME/.gemini/config/hooks.json"
[ -s "$AGY_HOOKS" ] || echo '{}' > "$AGY_HOOKS"

AGY_CMD="$AGY_CMD" NAMESPACE="{{ $namespace }}" "$PY" - "$AGY_HOOKS" <<'PY'
import json, os, sys

path = sys.argv[1]
cmd = os.environ["AGY_CMD"]
ns = os.environ["NAMESPACE"]

with open(path) as f:
    try:
        data = json.load(f)
    except Exception:
        data = {}

# Ensure data is a dictionary
if not isinstance(data, dict):
    data = {}

# We want to set data[ns] = { ... }
ns_data = data.setdefault(ns, {})
if not isinstance(ns_data, dict):
    ns_data = {}
    data[ns] = ns_data

# Simple events without matchers
for event in ["SessionStart", "PreInvocation", "Stop"]:
    ns_data[event] = [{"type": "command", "command": cmd}]

# Events with matchers (tool hooks)
for event in ["PreToolUse", "PostToolUse"]:
    ns_data[event] = [{
        "matcher": "*",
        "hooks": [{"type": "command", "command": cmd}]
    }]

with open(path, "w") as f:
    json.dump(data, f, indent=2)
    f.write("\n")
PY

echo "installed Antigravity CLI hooks -> $AGY_HOOKS"

if [ -z "{!! $envCheck !!}" ] && [ ! -s "$HOME/.config/{{ $namespace }}/token" ]; then
    echo ""
    echo "Next: save your token from the profile page into ~/.config/{{ $namespace }}/token."
fi

echo ""
echo "Tip: create ~/.config/{{ $namespace }}/custom.sh to customize what your fighter shows -- it survives every install and update."
