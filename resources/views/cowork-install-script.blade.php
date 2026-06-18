@php
    $envVar = strtoupper($namespace).'_TOKEN';
    $envCheck = '${'.$envVar.':-}';
    $envRead = '"$'.$envVar.'"';
@endphp
{!! '#!/bin/sh' !!}
# {{ $namespace }} Cowork watcher installer
# Installs a background watcher that reports Claude Cowork (agent mode) token
# usage to {{ $baseUrl }}. No browser or terminal hooks required.
#
# Pass {{ $envVar }}=<token> in the environment to save the token in the same
# run, e.g. `curl -fsSL ... | {{ $envVar }}=xxx sh`.
#
# Re-running this script is safe — it replaces the existing watcher and schedule.

set -e

PY=$(command -v python3 || command -v python || true)
if [ -z "$PY" ]; then
    echo "error: python3 (or python) is required for the Cowork watcher" >&2
    exit 1
fi

# Ensure token directory exists.
mkdir -p "$HOME/.config/{{ $namespace }}"

# If {{ $envVar }} was passed, save it so a single command does both.
if [ -n "{!! $envCheck !!}" ]; then
    TOKEN_FILE="$HOME/.config/{{ $namespace }}/token"
    printf '%s' {!! $envRead !!} > "$TOKEN_FILE"
    chmod 600 "$TOKEN_FILE"
    echo "saved token -> $TOKEN_FILE"
fi

# --- Cowork (Claude agent mode) token watcher ---
# Cowork tasks run Claude Code inside a sandbox VM and write standard transcripts
# to disk, but don't fire the host's hooks. A small timer-driven watcher reads
# those transcripts and reports output tokens. No browser needed.
WATCHER="$HOME/.config/{{ $namespace }}/cowork-watcher.py"
if curl -fsSL "{{ $watcherUrl }}" -o "$WATCHER"; then
    chmod +x "$WATCHER"
    case "$(uname -s)" in
        Darwin)
            PLIST="$HOME/Library/LaunchAgents/{{ $namespace }}.cowork.plist"
            mkdir -p "$HOME/Library/LaunchAgents"
            cat > "$PLIST" <<PLIST_EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>{{ $namespace }}.cowork</string>
    <key>ProgramArguments</key>
    <array><string>$PY</string><string>$WATCHER</string></array>
    <key>StartInterval</key><integer>120</integer>
    <key>RunAtLoad</key><true/>
</dict>
</plist>
PLIST_EOF
            launchctl unload "$PLIST" 2>/dev/null || true
            launchctl load "$PLIST" 2>/dev/null || true
            echo "installed Cowork watcher (launchd, every 2m) -> $PLIST"
            ;;
        Linux)
            if command -v systemctl >/dev/null 2>&1; then
                UNIT_DIR="$HOME/.config/systemd/user"
                mkdir -p "$UNIT_DIR"
                cat > "$UNIT_DIR/{{ $namespace }}-cowork.service" <<UNIT_EOF
[Unit]
Description=Token Slayer Cowork watcher
[Service]
Type=oneshot
ExecStart=$PY $WATCHER
UNIT_EOF
                cat > "$UNIT_DIR/{{ $namespace }}-cowork.timer" <<UNIT_EOF
[Unit]
Description=Run Token Slayer Cowork watcher every 2 minutes
[Timer]
OnBootSec=2min
OnUnitActiveSec=2min
[Install]
WantedBy=timers.target
UNIT_EOF
                systemctl --user daemon-reload 2>/dev/null || true
                systemctl --user enable --now {{ $namespace }}-cowork.timer 2>/dev/null || true
                echo "installed Cowork watcher (systemd timer, every 2m)"
            else
                ( crontab -l 2>/dev/null | grep -v "{{ $namespace }}-cowork"; echo "*/2 * * * * $PY $WATCHER >/dev/null 2>&1 # {{ $namespace }}-cowork" ) | crontab - 2>/dev/null \
                    && echo "installed Cowork watcher (cron, every 2m)" \
                    || echo "could not install Cowork watcher schedule (no systemd or cron)"
            fi
            ;;
        *)
            echo "Cowork watcher downloaded; auto-scheduling is set up on macOS/Linux only."
            ;;
    esac
else
    echo "warning: could not download Cowork watcher from {{ $watcherUrl }}"
fi

if [ -z "{!! $envCheck !!}" ] && [ ! -s "$HOME/.config/{{ $namespace }}/token" ]; then
    echo ""
    echo "Next: save your token from the profile page into ~/.config/{{ $namespace }}/token."
fi
