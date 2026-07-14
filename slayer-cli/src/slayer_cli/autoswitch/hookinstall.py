"""By-signature installer for the switcher's coordination hooks in
`~/.claude/settings.json`. Coexists with any other tool's hooks (including
token-slayer's own pre-existing attribution hook) by touching ONLY entries
whose command string carries our signature (`"token-slayer "`/`"slayer "`)
and round-tripping everything else verbatim — including non-hook keys like
`"model"`.

No tokens ever pass through settings.json; this module only shells out
command strings and JSON structure.
"""
from __future__ import annotations

import json
import os
from typing import Any

from slayer_cli.platform.paths import Paths

__all__ = ["install", "uninstall", "installed"]

# (hook_event, `hook` subcommand, timeout in seconds).
_SPECS: list[tuple[str, str, int]] = [
    ("SessionStart", "session-start", 10),
    ("Stop", "stop", 10),
    ("PostToolUseFailure", "rate-limit", 10),
    ("StopFailure", "rate-limit", 10),
    ("UserPromptSubmit", "prompt-submit", 10),
]

# Substrings that mark a hook command as ours, for by-signature matching.
_SIGNATURES: tuple[str, ...] = ("token-slayer ", "slayer ")


def _hook_bin() -> str:
    """The executable the installed hook command invokes.

    Defaults to `token-slayer`. Set `TS_HOOK_BIN` to an absolute path when a
    different `token-slayer` (e.g. the event-attribution shim) shadows this
    CLI on PATH, so Claude Code's hooks reach THIS slayer-cli. The value must
    still carry our signature (contain `slayer`) for by-signature detection.

    :return: The command prefix the hook invokes (before ` hook <sub>`).
    """
    return os.environ.get("TS_HOOK_BIN") or "token-slayer"


def _is_ours(entry: dict[str, Any]) -> bool:
    """Return whether a settings.json hook entry carries our signature.

    An entry is ours if any of its `hooks[].command` strings contain
    `"token-slayer "` or `"slayer "`.

    :param entry: A single `{"matcher": ..., "hooks": [...]}` entry.
    :return: True if the entry is one of ours, else False.
    """
    for h in entry.get("hooks", []):
        command = h.get("command", "")
        if any(sig in command for sig in _SIGNATURES):
            return True
    return False


def _load(paths: Paths) -> dict[str, Any]:
    """Load settings.json as a dict, defaulting to {} if missing/invalid.

    :param paths: Resolved filesystem paths for this namespace.
    :return: Parsed settings dict.
    """
    settings_file = paths.settings_file
    if not settings_file.is_file():
        return {}
    try:
        data = json.loads(settings_file.read_text())
    except ValueError:
        return {}
    return data if isinstance(data, dict) else {}


def _save(paths: Paths, data: dict[str, Any]) -> None:
    """Atomically write settings.json, mode 0644 (matches Claude Code's own).

    :param paths: Resolved filesystem paths for this namespace.
    :param data: Full settings dict to persist.
    :return: None
    """
    settings_file = paths.settings_file
    settings_file.parent.mkdir(parents=True, exist_ok=True)
    tmp = settings_file.with_suffix(".tmp")
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o644)
    with os.fdopen(fd, "w") as handle:
        handle.write(json.dumps(data, indent=2))
    tmp.replace(settings_file)


def install(paths: Paths) -> None:
    """Upsert our coordination-hook entries into settings.json.

    For each spec's event, drops any existing entries carrying our
    signature and appends the desired one, leaving foreign entries (and
    every non-hook key) untouched.

    :param paths: Resolved filesystem paths for this namespace.
    :return: None
    """
    data = _load(paths)
    hooks_by_event = data.setdefault("hooks", {})
    for event, sub, timeout in _SPECS:
        entries = [e for e in hooks_by_event.get(event, []) if not _is_ours(e)]
        entries.append({
            "matcher": ".*",
            "hooks": [{"type": "command", "command": f"{_hook_bin()} hook {sub}", "timeout": timeout}],
        })
        hooks_by_event[event] = entries
    _save(paths, data)


def uninstall(paths: Paths) -> None:
    """Strip only our coordination-hook entries from settings.json.

    Foreign entries and non-hook keys are left untouched. Events left with
    no entries are removed entirely to keep settings.json tidy.

    :param paths: Resolved filesystem paths for this namespace.
    :return: None
    """
    data = _load(paths)
    hooks_by_event = data.get("hooks", {})
    for event, _sub, _timeout in _SPECS:
        if event not in hooks_by_event:
            continue
        remaining = [e for e in hooks_by_event[event] if not _is_ours(e)]
        if remaining:
            hooks_by_event[event] = remaining
        else:
            del hooks_by_event[event]
    _save(paths, data)


def installed(paths: Paths) -> bool:
    """Return whether all of our coordination-hook specs are present.

    :param paths: Resolved filesystem paths for this namespace.
    :return: True if every spec's event has an entry carrying our
        signature with the expected command, else False.
    """
    data = _load(paths)
    hooks_by_event = data.get("hooks", {})
    for event, sub, _timeout in _SPECS:
        expected = f"{_hook_bin()} hook {sub}"
        entries = hooks_by_event.get(event, [])
        if not any(
            any(expected in h.get("command", "") for h in e.get("hooks", []))
            for e in entries
            if _is_ours(e)
        ):
            return False
    return True
