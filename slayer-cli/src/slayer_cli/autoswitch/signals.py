"""PID-namespaced file bus for hook↔wrapper event signaling.

Signal files live at `paths.signals_dir / f"{pid}-{name}"` and are atomic 0600
(dir 0700). Used to communicate transient events (session started, rate limited,
switch requested) between Claude Code's hooks (writers) and the wrapper (reader).

No tokens go through signals; payloads are session IDs, error messages, switch targets.
"""
from __future__ import annotations
import json
import os
from pathlib import Path
from slayer_cli.platform.paths import Paths

# Signal name constants.
SESSION_STARTED = "session-started"
STOPPED = "stopped"
RATE_LIMITED = "rate-limited"
SWITCH_REQUESTED = "switch-requested"
TURN_FAILED = "turn-failed"


def _signal_path(paths: Paths, pid: int, name: str) -> Path:
    """Return the signal file path for `pid` and `name`.

    :param paths: Resolved OS paths.
    :param pid: Process ID.
    :param name: Signal name.
    :return: Path to the signal file.
    """
    return paths.signals_dir / f"{pid}-{name}"


def write(paths: Paths, pid: int, name: str, payload: dict | None) -> None:
    """Write a signal file atomically at mode 0600 (dir 0700).

    A `None` payload writes an empty marker file (read back as {}).

    :param paths: Resolved OS paths.
    :param pid: Process ID.
    :param name: Signal name.
    :param payload: Optional dict payload, or None for a marker file.
    :return: None
    """
    d = paths.signals_dir
    d.mkdir(parents=True, exist_ok=True)
    os.chmod(d, 0o700)

    path = _signal_path(paths, pid, name)
    content = json.dumps(payload) if payload is not None else ""
    tmp = path.with_suffix(".tmp")
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as h:
        h.write(content)
    tmp.replace(path)


def read(paths: Paths, pid: int, name: str) -> dict | None:
    """Read a signal file, returning the parsed dict, {} for a marker, or None if absent.

    Corrupt JSON is treated as {} (tolerant to corruption).

    :param paths: Resolved OS paths.
    :param pid: Process ID.
    :param name: Signal name.
    :return: Parsed dict if file contains JSON, {} if file is empty, None if absent.
    """
    path = _signal_path(paths, pid, name)
    if not path.is_file():
        return None
    try:
        content = path.read_text()
        if not content:
            return {}
        return json.loads(content)
    except ValueError:
        return {}


def consume(paths: Paths, pid: int, name: str) -> None:
    """Unlink a signal file (idempotent, missing_ok).

    :param paths: Resolved OS paths.
    :param pid: Process ID.
    :param name: Signal name.
    :return: None
    """
    _signal_path(paths, pid, name).unlink(missing_ok=True)


def cleanup_for_pid(paths: Paths, pid: int) -> None:
    """Unlink all signal files for `pid` (glob `f"{pid}-*"`).

    :param paths: Resolved OS paths.
    :param pid: Process ID.
    :return: None
    """
    d = paths.signals_dir
    if not d.is_dir():
        return
    for f in d.glob(f"{pid}-*"):
        f.unlink(missing_ok=True)
