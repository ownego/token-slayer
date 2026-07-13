"""Hook bodies for `token-slayer hook <sub>`, invoked directly by Claude Code
as configured hooks. Each function is gated by `TS_WRAPPED` — outside
`token-slayer run` the env var is unset, so every hook is a harmless no-op
(exit 0), which keeps these hooks safe to install unconditionally.

No tokens ever flow through these hooks: they read Claude Code's hook JSON
(session IDs, prompts, error text — never credentials) and write signal
files consumed by the wrapper (see `autoswitch.signals`).
"""
from __future__ import annotations

import json
import os
import re
from typing import TextIO

from slayer_cli.autoswitch import classify, signals
from slayer_cli.platform.paths import Paths

__all__ = ["session_start", "stop", "rate_limit", "prompt_submit"]

# Matches `/switch` or `/switch <target>`, capturing the trailing target text.
_SWITCH_PATTERN = re.compile(r"^/switch\b\s*(.*)$")

# Matches the `/ts:<cmd>` slash-command prefix.
_TS_PATTERN = re.compile(r"^/ts:(\S*)")


def _read_json(stdin: TextIO) -> dict:
    """Parse a hook's stdin JSON payload, tolerating missing/empty/invalid input.

    :param stdin: Stream to read the hook JSON payload from.
    :return: Parsed dict, or {} if stdin is empty or not valid JSON.
    """
    try:
        raw = stdin.read()
    except Exception:
        return {}
    if not raw:
        return {}
    try:
        data = json.loads(raw)
    except ValueError:
        return {}
    return data if isinstance(data, dict) else {}


def _wrapper_pid() -> int | None:
    """Resolve the wrapper's PID from `TS_WRAPPER_PID`.

    :return: The wrapper PID, or None if unset/invalid.
    """
    raw = os.environ.get("TS_WRAPPER_PID")
    if not raw:
        return None
    try:
        return int(raw)
    except ValueError:
        return None


def _is_wrapped() -> bool:
    """Return whether this hook is running inside `token-slayer run`.

    :return: True if `TS_WRAPPED=1`, else False.
    """
    return os.environ.get("TS_WRAPPED") == "1"


def session_start(stdin: TextIO, stdout: TextIO) -> None:
    """Handle the SessionStart hook: write SESSION_STARTED{sessionId, cwd}.

    No-op outside a wrapped session.

    :param stdin: Stream carrying Claude Code's hook JSON payload.
    :param stdout: Stream for hook stdout (unused; present for interface symmetry).
    :return: None
    """
    if not _is_wrapped():
        return
    pid = _wrapper_pid()
    if pid is None:
        return
    data = _read_json(stdin)
    payload = {"sessionId": data.get("session_id"), "cwd": data.get("cwd")}
    signals.write(Paths(Paths.current_ns()), pid, signals.SESSION_STARTED, payload)


def stop(stdin: TextIO) -> None:
    """Handle the Stop hook: write STOPPED{sessionId}.

    No-op outside a wrapped session. Writes the signal unconditionally — presence
    of the STOPPED signal is the event, payload may be empty (sessionId is None).

    :param stdin: Stream carrying Claude Code's hook JSON payload.
    :return: None
    """
    if not _is_wrapped():
        return
    pid = _wrapper_pid()
    if pid is None:
        return
    data = _read_json(stdin)
    signals.write(Paths(Paths.current_ns()), pid, signals.STOPPED, {"sessionId": data.get("session_id")})


def rate_limit(stdin: TextIO) -> None:
    """Handle a failure hook: classify the error and write RATE_LIMITED/TURN_FAILED.

    Reads the error text from `error`, falling back to `error_details` then
    `last_assistant_message`. Writes nothing when `classify_failure` finds no
    recognized pattern. No-op outside a wrapped session.

    :param stdin: Stream carrying Claude Code's hook JSON payload.
    :return: None
    """
    if not _is_wrapped():
        return
    pid = _wrapper_pid()
    if pid is None:
        return
    data = _read_json(stdin)
    error_text = data.get("error") or data.get("error_details") or data.get("last_assistant_message") or ""
    event_name = data.get("hook_event_name") or ""
    name, text = classify.classify_failure(error_text, event_name)
    if name is None:
        return
    signals.write(Paths(Paths.current_ns()), pid, name, {"error": text})


def prompt_submit(stdin: TextIO, stdout: TextIO) -> None:
    """Handle the UserPromptSubmit hook: intercept `/switch` and `/ts:` prompts.

    `/switch [target]` writes SWITCH_REQUESTED{target} (target empty = rotate).
    `/ts:<cmd>` writes a short inline hint to stdout (full rendering is a
    later task). Any other prompt passes through untouched. No-op outside a
    wrapped session.

    :param stdin: Stream carrying Claude Code's hook JSON payload.
    :param stdout: Stream to write an inline hint to, for `/ts:` prompts.
    :return: None
    """
    if not _is_wrapped():
        return
    pid = _wrapper_pid()
    if pid is None:
        return
    data = _read_json(stdin)
    prompt = data.get("prompt") or ""

    switch_match = _SWITCH_PATTERN.match(prompt)
    if switch_match:
        target = switch_match.group(1).strip()
        signals.write(Paths(Paths.current_ns()), pid, signals.SWITCH_REQUESTED, {"target": target})
        return

    ts_match = _TS_PATTERN.match(prompt)
    if ts_match:
        cmd = ts_match.group(1) or "<cmd>"
        stdout.write(f"run token-slayer {cmd}\n")
        return
