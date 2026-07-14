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

from slayer_cli.accounts.store import AccountStore
from slayer_cli.autoswitch import classify, signals
from slayer_cli.config import store as config_store
from slayer_cli.models.usage_windows import AccountUsage
from slayer_cli.platform.paths import Paths
from slayer_cli.usage import cache as usage_cache

__all__ = ["session_start", "stop", "rate_limit", "prompt_submit"]

# Matches `/switch` or `/switch <target>`, capturing the trailing target text.
_SWITCH_PATTERN = re.compile(r"^/switch\b\s*(.*)$")

# Matches the `/ts:<cmd>` slash-command prefix, capturing the command word and
# any trailing argument text (e.g. `/ts:switch work` -> ("switch", "work")).
_TS_PATTERN = re.compile(r"^/ts:(\S*)\s*(.*)$")

# `/ts:` subcommands that render the account/usage summary (aliases of the same view).
_TS_SUMMARY_COMMANDS = frozenset({"list", "status", "usage"})


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
    """Handle the SessionStart hook: write SESSION_STARTED{sessionId, cwd, transcriptPath}.

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
    payload = {
        "sessionId": data.get("session_id"),
        "cwd": data.get("cwd"),
        "transcriptPath": data.get("transcript_path"),
    }
    signals.write(Paths(Paths.current_ns()), pid, signals.SESSION_STARTED, payload)


def stop(stdin: TextIO) -> None:
    """Handle the Stop hook: write STOPPED{sessionId, transcriptPath}.

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
    signals.write(Paths(Paths.current_ns()), pid, signals.STOPPED,
                  {"sessionId": data.get("session_id"), "transcriptPath": data.get("transcript_path")})


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


def _usage_fragment(usage: AccountUsage | None) -> str:
    """Render a one-line utilization fragment for `/ts:list`-style output.

    :param usage: Cached usage for the account, or None when unpolled.
    :return: `"5h NN%  7d NN%"`, with `—` for missing windows, or
        `"no cached usage yet"` when `usage` is `None`.
    """
    if usage is None:
        return "no cached usage yet"
    five = f"{usage.five_hour.utilization:.0f}%" if usage.five_hour else "—"
    seven = f"{usage.seven_day.utilization:.0f}%" if usage.seven_day else "—"
    return f"5h {five}  7d {seven}"


def _render_account_summary(paths: Paths) -> str:
    """Render a token-free account/usage summary for `/ts:list|status|usage`.

    Reads the already-populated usage cache (`usage.cache.load_cache`,
    written by the wrapper's poll loop) — never fetches over the network,
    so the hook stays fast and never blocks the prompt on I/O.

    :param paths: Resolved OS paths for this namespace.
    :return: A friendly "no accounts" line when no slots exist, else one
        line per account slot (marker, name, email, cached utilization).
    """
    store = AccountStore(paths)
    accounts = store.list()
    if not accounts:
        return "No account slots yet. Add one with `token-slayer add <name>`."
    active = store.active()
    cache = usage_cache.load_cache(paths)
    lines = ["Accounts:"]
    for account in accounts:
        marker = "*" if account.name == active else " "
        usage = cache.get(usage_cache.cache_key(account))
        lines.append(f"{marker} {account.name}  {account.email or '-'}  {_usage_fragment(usage)}")
    return "\n".join(lines)


def _render_config(paths: Paths) -> str:
    """Render the current behaviour config as indented JSON for `/ts:config`.

    :param paths: Resolved OS paths for this namespace.
    :return: `Config.model_dump_json(indent=2)` for the loaded config.
    """
    cfg = config_store.load(paths)
    return cfg.model_dump_json(indent=2)


def prompt_submit(stdin: TextIO, stdout: TextIO) -> None:
    """Handle the UserPromptSubmit hook: intercept `/switch` and `/ts:` prompts.

    `/switch [target]` writes SWITCH_REQUESTED{target} (target empty = rotate).
    `/ts:list`, `/ts:status`, and `/ts:usage` render an inline account/usage
    summary (never a token). `/ts:config` renders the current config as JSON.
    `/ts:switch [target]` behaves exactly like `/switch`. Any other `/ts:<cmd>`
    falls back to a short inline hint. Any other prompt passes through
    untouched. No-op outside a wrapped session.

    :param stdin: Stream carrying Claude Code's hook JSON payload.
    :param stdout: Stream to write inline rendering to, for `/ts:` prompts.
    :return: None
    """
    if not _is_wrapped():
        return
    pid = _wrapper_pid()
    if pid is None:
        return
    data = _read_json(stdin)
    prompt = data.get("prompt") or ""
    paths = Paths(Paths.current_ns())

    switch_match = _SWITCH_PATTERN.match(prompt)
    if switch_match:
        target = switch_match.group(1).strip()
        signals.write(paths, pid, signals.SWITCH_REQUESTED, {"target": target})
        return

    ts_match = _TS_PATTERN.match(prompt)
    if ts_match:
        cmd = ts_match.group(1) or ""
        rest = ts_match.group(2).strip()
        if cmd in _TS_SUMMARY_COMMANDS:
            stdout.write(_render_account_summary(paths) + "\n")
            return
        if cmd == "config":
            stdout.write(_render_config(paths) + "\n")
            return
        if cmd == "switch":
            signals.write(paths, pid, signals.SWITCH_REQUESTED, {"target": rest})
            return
        stdout.write(f"run token-slayer {cmd or '<cmd>'}\n")
        return
