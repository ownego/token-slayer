"""Pure rendering-decision helpers for the Accounts page: bar width/color,
the limit-hit flag, and reset-time formatting. Ported from ccm's
`dashboard.py` (`_full_bar`, `_bar_color`, `_reset_str`, `_time_until`) —
kept dependency-free of Textual so they're unit-testable without a terminal.

`full_bar` reuses `usage.parser.bar`'s fill algorithm at ccm's detail-panel
width (24); only the pieces ccm's `dashboard.py` had that `usage.parser`
doesn't (color thresholds, the limit flag, reset formatting) live here.
"""
from __future__ import annotations

import time
from datetime import datetime

from slayer_cli.usage.parser import bar as _bar

FULL_BAR_WIDTH = 24
"""Detail-panel bar width, ccm's `_full_bar` default."""


def full_bar(pct: int | None, width: int = FULL_BAR_WIDTH) -> str:
    """Render the wide detail-panel usage bar (ccm's `_full_bar`).

    :param pct: Percentage (0-100), or `None` (renders fully empty).
    :param width: Total bar width in characters.
    :return: A `width`-character filled/empty bar string.
    """
    return _bar(pct, width)


def bar_color(pct: int | None) -> str:
    """Map a percentage to a Rich/Textual style name (ccm's `_bar_color`).

    :param pct: Percentage (0-100), or `None`.
    :return: `"dim"` when `pct` is `None`, `"bold red"` at 90+, `"yellow"`
        at 70-89, else `"green"`.
    """
    if pct is None:
        return "dim"
    if pct >= 90:
        return "bold red"
    if pct >= 70:
        return "yellow"
    return "green"


def is_limited(status: str | None) -> bool:
    """Whether the bucket's status should show the `LIMIT HIT` flag.

    :param status: The `s5h_status` value from a `UsageSnapshot`.
    :return: `True` when `status == "limited"`.
    """
    return status == "limited"


def reset_countdown(ts: int | None, *, now: int | None = None) -> str:
    """Render a human countdown until `ts` (ccm's `_time_until`).

    :param ts: Reset unix timestamp, or `None`.
    :param now: Current unix timestamp to diff against; defaults to
        `time.time()`. Exposed for deterministic tests.
    :return: `"—"` when `ts` is `None`, `"now"` under a minute away,
        else `"in <N>d <N>h"` / `"in <N>h <N>m"` / `"in <N>m"`.
    """
    if ts is None:
        return "—"
    current = int(time.time()) if now is None else now
    diff = int(ts - current)
    if diff < 60:
        return "now"
    days = diff // 86400
    hours = (diff % 86400) // 3600
    minutes = (diff % 3600) // 60
    if days > 0:
        return f"in {days}d {hours}h"
    if hours > 0:
        return f"in {hours}h {minutes}m"
    return f"in {minutes}m"


def reset_clock(ts: int | None, clock_format: str = "%H:%M") -> str:
    """Format a reset timestamp as a clock string (ccm's `_reset_str`).

    :param ts: Reset unix timestamp, or `None`.
    :param clock_format: `strftime` format string.
    :return: The formatted clock string, or `"—"` when `ts` is `None` or
        out of range for `datetime.fromtimestamp`.
    """
    if ts is None:
        return "—"
    try:
        return datetime.fromtimestamp(ts).strftime(clock_format)
    except (OverflowError, OSError, ValueError):
        return "—"
