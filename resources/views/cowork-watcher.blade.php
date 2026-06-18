{!! '#!/usr/bin/env python3' !!}
"""Token Slayer Cowork tracker.

Scans Claude Cowork (local agent mode) transcripts and reports the output
tokens of new assistant turns to Token Slayer as boss damage. Runs on a timer
(launchd on macOS, systemd/cron on Linux); no browser required.

Tokens are read straight from the transcript's `message.usage.output_tokens`,
so they are exact rather than estimated.
"""
import json
import os
import sys
import urllib.error
import urllib.request

API_URL = "{{ $eventsUrl }}"
TOKEN_FILE = os.path.expanduser("~/.config/{{ $namespace }}/token")
STATE_FILE = os.path.expanduser("~/.config/{{ $namespace }}/cowork-state.json")


def claude_base():
    """Per-OS Electron app-data directory for Claude Desktop."""
    home = os.path.expanduser("~")
    if sys.platform == "darwin":
        return os.path.join(home, "Library", "Application Support", "Claude")
    if sys.platform.startswith("win"):
        return os.path.join(os.environ.get("APPDATA", ""), "Claude")
    return os.path.join(home, ".config", "Claude")


def transcript_paths():
    # Cowork runs Claude Code inside a sandbox VM, so each task writes standard
    # transcripts under a nested `.claude/projects/<encoded-cwd>/<uuid>.jsonl`.
    # Walk (not glob) because that path includes a hidden `.claude` directory,
    # which glob's `**` skips by default.
    base = os.path.join(claude_base(), "local-agent-mode-sessions")
    marker = os.path.join(".claude", "projects")
    paths = []
    for root, _dirs, files in os.walk(base):
        if marker in root:
            paths.extend(
                os.path.join(root, name)
                for name in files
                if name.endswith(".jsonl")
            )
    return paths


def read_token():
    try:
        with open(TOKEN_FILE) as handle:
            return handle.read().strip()
    except OSError:
        return ""


def load_state():
    try:
        with open(STATE_FILE) as handle:
            return json.load(handle)
    except (OSError, ValueError):
        return {}


def save_state(state):
    os.makedirs(os.path.dirname(STATE_FILE), exist_ok=True)
    with open(STATE_FILE, "w") as handle:
        json.dump(state, handle)


def session_id_for(path):
    return os.path.splitext(os.path.basename(path))[0]


def process_file(path, offset):
    """Sum output_tokens of assistant entries written since `offset` bytes.

    Reads only the bytes after `offset` (via seek) so growing transcripts —
    common for heavy Cowork tasks — aren't re-read in full every run. Only
    whole lines are consumed: a partially-flushed final line is left for the
    next pass. Returns ``(tokens, new_offset)``.
    """
    try:
        size = os.path.getsize(path)
    except OSError:
        return 0, offset

    # File was truncated or replaced — start it over from the top.
    if offset > size:
        offset = 0
    if offset == size:
        return 0, offset

    with open(path, "rb") as handle:
        handle.seek(offset)
        data = handle.read()

    last_newline = data.rfind(b"\n")
    if last_newline == -1:
        # No complete line yet; wait for the rest to be flushed.
        return 0, offset
    complete = data[: last_newline + 1]

    tokens = 0
    for raw in complete.split(b"\n"):
        if not raw:
            continue
        try:
            entry = json.loads(raw)
        except ValueError:
            continue
        if entry.get("type") == "assistant":
            usage = entry.get("message", {}).get("usage", {})
            tokens += int(usage.get("output_tokens") or 0)

    return tokens, offset + len(complete)


def report(token, session_id, tokens):
    body = json.dumps(
        {"hook_event_name": "Stop", "session_id": session_id, "tokens": tokens}
    ).encode()
    request = urllib.request.Request(
        API_URL,
        data=body,
        method="POST",
        headers={
            "Authorization": "Bearer " + token,
            "Content-Type": "application/json",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=5) as response:
            return response.status
    except urllib.error.HTTPError as error:
        return error.code
    except Exception:
        return None


def main():
    token = read_token()
    if not token:
        return

    state = load_state()
    # First run only baselines: existing transcripts are marked processed
    # without dealing damage, so installing doesn't dump history onto the boss.
    baselining = not state.get("_baselined", False)

    for path in transcript_paths():
        tokens, new_offset = process_file(path, state.get(path, 0))
        state[path] = new_offset
        if not baselining and tokens > 0:
            report(token, session_id_for(path), tokens)

    state["_baselined"] = True
    save_state(state)


if __name__ == "__main__":
    main()
