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

curl -s --max-time 3 -X POST "$URL" \
  -H "Authorization: Bearer $(cat "$TOKEN_FILE")" \
  -H 'Content-Type: application/json' \
  -d "$BODY" >/dev/null 2>&1 &
HOOK_SH
chmod +x "$HELPER"

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

CLAUDE_CMD="$CLAUDE_CMD" "$PY" - "$SETTINGS" <<'PY'
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
for event in events:
    data["hooks"][event] = [{"hooks": [{"type": "command", "command": cmd}]}]

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
