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

# Drop the hook helper script. Stop events are enriched with a tokens count
# parsed from the local Claude transcript (requires jq when available),
# because the server cannot read the user's filesystem.
HELPER="$HOME/.config/{{ $namespace }}/send-hook.sh"
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
NS_DIR="$HOME/.config/{{ $namespace }}"

sha256() { sha256sum 2>/dev/null | cut -d' ' -f1 || shasum -a 256 | cut -d' ' -f1; }

current_access_token() {
  # Same lookup order Claude Code uses; hooks inherit CLAUDE_CONFIG_DIR.
  for f in "${CLAUDE_CONFIG_DIR:-}/.credentials.json" "$HOME/.claude/.credentials.json"; do
    [ -r "$f" ] || continue
    jq -r '.claudeAiOauth.accessToken // ""' "$f" 2>/dev/null && return
  done
  if [ "$(uname)" = "Darwin" ]; then
    security find-generic-password -s "Claude Code-credentials" -w 2>/dev/null \
      | jq -r '.claudeAiOauth.accessToken // ""' 2>/dev/null
  fi
}

resolve_account() {
  ACC_EMAIL="" ACC_UUID="" ACC_SOURCE=""

  # 1. Manual override wins unconditionally.
  if [ -r "$NS_DIR/account.json" ]; then
    ACC_EMAIL=$(jq -r '.email // ""' "$NS_DIR/account.json" 2>/dev/null)
    ACC_UUID=$(jq -r '.uuid // ""' "$NS_DIR/account.json" 2>/dev/null)
    [ -n "$ACC_EMAIL" ] && { ACC_SOURCE="manual"; return; }
  fi

  # 0. Proxy detect: base URL rerouted -> client cannot know the account. Don't guess.
  case "${ANTHROPIC_BASE_URL:-}" in
    ""|*api.anthropic.com*) ;;
    *) ACC_SOURCE="proxy"; ACC_EMAIL=""; ACC_UUID=""; return ;;
  esac

  # 2. Credential identity: fingerprint the live token, ask the profile API once per token.
  TOKEN=$(current_access_token)
  if [ -n "$TOKEN" ]; then
    FP=$(printf '%s' "$TOKEN" | sha256)
    CACHE="$NS_DIR/identity-cache.json"
    if [ -r "$CACHE" ]; then
      ACC_EMAIL=$(jq -r --arg fp "$FP" '.[$fp].email // ""' "$CACHE" 2>/dev/null)
      ACC_UUID=$(jq -r --arg fp "$FP" '.[$fp].uuid // ""' "$CACHE" 2>/dev/null)
    fi
    if [ -z "$ACC_EMAIL" ]; then
      PROFILE=$(curl -sf --max-time 5 "https://api.anthropic.com/api/oauth/profile" \
        -H "Authorization: Bearer $TOKEN" -H "anthropic-beta: oauth-2025-04-20" 2>/dev/null)
      ACC_EMAIL=$(printf '%s' "$PROFILE" | jq -r '.account.email // .account.email_address // .email // ""' 2>/dev/null)
      ACC_UUID=$(printf '%s' "$PROFILE" | jq -r '.account.uuid // .account_uuid // ""' 2>/dev/null)
      if [ -n "$ACC_EMAIL" ]; then
        TMP=$(mktemp) && jq --arg fp "$FP" --arg e "$ACC_EMAIL" --arg u "$ACC_UUID" \
          '. + {($fp): {email: $e, uuid: $u}}' "$CACHE" 2>/dev/null > "$TMP" \
          || printf '{"%s":{"email":"%s","uuid":"%s"}}' "$FP" "$ACC_EMAIL" "$ACC_UUID" > "$TMP"
        mv "$TMP" "$CACHE"
      fi
    fi
    [ -n "$ACC_EMAIL" ] && { ACC_SOURCE="credential"; return; }
  fi

  # 3. Fallback: oauthAccount (may be stale under external switchers).
  CJ="${CLAUDE_CONFIG_DIR:-$HOME}/.claude.json"
  [ -r "$CJ" ] || CJ="$HOME/.claude.json"
  if [ -r "$CJ" ]; then
    ACC_EMAIL=$(jq -r '.oauthAccount.emailAddress // ""' "$CJ" 2>/dev/null)
    ACC_UUID=$(jq -r '.oauthAccount.accountUuid // ""' "$CJ" 2>/dev/null)
    [ -n "$ACC_EMAIL" ] && ACC_SOURCE="auto"
  fi
}

if command -v jq >/dev/null 2>&1; then
  resolve_account
  BODY=$(printf '%s' "$BODY" | jq -c --arg e "$ACC_EMAIL" --arg u "$ACC_UUID" \
    --arg s "$ACC_SOURCE" --arg v "$CLIENT_VERSION" \
    '. + {client_version: $v} + (if $s != "" then {account_source: $s} else {} end)
       + (if $e != "" then {account_email: $e, account_uuid: $u} else {} end)' \
    2>/dev/null || printf '%s' "$BODY")
fi

curl -s --max-time 3 -X POST "$URL" \
  -H "Authorization: Bearer $(cat "$TOKEN_FILE")" \
  -H 'Content-Type: application/json' \
  -d "$BODY" >/dev/null 2>&1 &
HOOK_SH
chmod +x "$HELPER"

printf '%s' "{{ $clientVersion }}" > "$HOME/.config/{{ $namespace }}/version"

mkdir -p "$HOME/.local/bin"
cat > "$HOME/.local/bin/token-slayer" <<'CLI_SH'
#!/usr/bin/env bash
set -u
NS_DIR="$HOME/.config/{{ $namespace }}"
INSTALL_URL='{{ $installUrl }}'
LATEST='{{ $clientVersion }}'
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
    entries.append({"hooks": [{"type": "command", "command": cmd}]})
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
