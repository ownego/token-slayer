"""Relaunch helpers: flag stripping, session-id resolution, fibonacci backoff."""
from __future__ import annotations

import os
from pathlib import Path


def relaunch_argv(
    orig_argv: list[str],
    session_id: str | None,
    *,
    auto_resume: bool,
    auto_message: str,
) -> list[str]:
    """Build a new argv for relaunching Claude Code with updated session handling.

    Strips session-specific flags (--resume/-r and -p/--print) from orig_argv,
    then appends --resume <session_id> if auto_resume is True and session_id
    is truthy, and appends auto_message as a trailing positional if non-empty.

    :param orig_argv: Original command-line arguments.
    :param session_id: Session ID to use for --resume flag (if auto_resume is True).
    :param auto_resume: Whether to append --resume <session_id>.
    :param auto_message: Message to append as trailing positional (if non-empty).
    :return: Rebuilt argv list.
    """
    out = []
    i = 0
    while i < len(orig_argv):
        arg = orig_argv[i]

        # Strip --resume and its value
        if arg == "--resume":
            i += 2  # skip both the flag and its value
            continue

        # Strip -r and its value (short form of --resume)
        if arg == "-r":
            i += 2  # skip both the flag and its value
            continue

        # Strip -p/--print (no value)
        if arg in ("-p", "--print"):
            i += 1
            continue

        # Keep everything else
        out.append(arg)
        i += 1

    # Append --resume <session_id> if auto_resume and session_id is truthy
    if auto_resume and session_id:
        out.append("--resume")
        out.append(session_id)

    # Append auto_message as trailing positional if non-empty
    if auto_message:
        out.append(auto_message)

    return out


def session_id_from(env_session_id: str, transcript_dir: str) -> str | None:
    """Resolve session ID from environment or newest transcript file.

    Returns env_session_id if non-empty, otherwise the basename (without .jsonl
    extension) of the newest *.jsonl file in transcript_dir by mtime, or None
    if no transcript files found.

    :param env_session_id: Environment variable value (CLAUDE_SESSION_ID).
    :param transcript_dir: Directory path containing transcript .jsonl files.
    :return: Session ID string or None.
    """
    # Return env value if non-empty
    if env_session_id:
        return env_session_id

    # Find newest .jsonl file by mtime
    try:
        entries = list(
            os.scandir(transcript_dir)
        )  # all entries in the directory
        jsonl_entries = [
            e for e in entries if e.name.endswith(".jsonl") and e.is_file()
        ]

        if not jsonl_entries:
            return None

        # Sort by mtime (descending) and take the first
        newest = max(jsonl_entries, key=lambda e: e.stat().st_mtime)
        # Return basename without .jsonl extension
        return newest.name[:-6]  # Remove ".jsonl"
    except (OSError, FileNotFoundError):
        return None


def fibonacci_delay(n: int, cap: float = 60.0) -> float:
    """Return fibonacci backoff delay for retry attempt n.

    Computes the nth fibonacci number (1, 1, 2, 3, 5, 8, ...) and caps
    the result to prevent excessive delays.

    :param n: Retry attempt index (0, 1, 2, ...).
    :param cap: Maximum delay in seconds (default 60).
    :return: Fibonacci-sequence delay value, capped.
    """
    if n <= 1:
        return min(1.0, cap)

    a, b = 1.0, 1.0
    for _ in range(n - 1):
        a, b = b, a + b

    return min(b, cap)
