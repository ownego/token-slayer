@php
    $envVar = strtoupper($namespace).'_TOKEN';
    $envCheck = '${'.$envVar.':-}';
    $envRead = '"$'.$envVar.'"';
@endphp
{!! '#!/bin/sh' !!}
# {{ $namespace }} hook installer
# Installs Claude Code + Codex CLI hooks that POST to {{ $baseUrl }}.
# Hooks read the token at runtime from ~/.config/{{ $namespace }}/token.
#
# Pass {{ $envVar }}=<token> in the environment to save the token in the
# same run, e.g. `curl -fsSL ... | {{ $envVar }}=xxx sh`. Otherwise the
# token must be written separately.
#
# Re-running this script is safe — existing {{ $namespace }} hook blocks are
# replaced, other settings in your config files are preserved.

set -e

CLAUDE_CMD="curl -s --max-time 3 -X POST '{{ $baseUrl }}' -H 'Authorization: Bearer '\$(cat ~/.config/{{ $namespace }}/token) -H 'Content-Type: application/json' -d @- >/dev/null 2>&1 &"
CODEX_CMD="curl -s --max-time 3 -X POST '{{ $baseUrl }}?provider=codex' -H 'Authorization: Bearer '\$(cat ~/.config/{{ $namespace }}/token) -H 'Content-Type: application/json' -d @- >/dev/null 2>&1 &"

PY=$(command -v python3 || command -v python || true)
if [ -z "$PY" ]; then
    echo "error: python3 (or python) is required to merge ~/.claude/settings.json" >&2
    exit 1
fi

# Ensure token directory exists.
mkdir -p "$HOME/.config/{{ $namespace }}"

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

if [ -z "{!! $envCheck !!}" ] && [ ! -s "$HOME/.config/{{ $namespace }}/token" ]; then
    echo ""
    echo "Next: save your token from the profile page into ~/.config/{{ $namespace }}/token."
fi
