"""Anthropic ratelimit response headers -> `UsageSnapshot`, plus the `pct`/`bar`
display helpers used to render utilization in the TUI."""
from __future__ import annotations
from typing import Mapping
from slayer_cli.models.usage import UsageSnapshot

_5H_UTIL_HEADER = "anthropic-ratelimit-unified-5h-utilization"
_5H_STATUS_HEADER = "anthropic-ratelimit-unified-5h-status"
_5H_RESET_HEADER = "anthropic-ratelimit-unified-5h-reset"
_7D_UTIL_HEADER = "anthropic-ratelimit-unified-7d-utilization"
_7D_RESET_HEADER = "anthropic-ratelimit-unified-7d-reset"


def parse_headers(headers: Mapping[str, str]) -> UsageSnapshot:
    """Parse Anthropic's ratelimit response headers into a `UsageSnapshot`.

    Only the 5h bucket carries a `-status` header; the 7d bucket does not.

    :param headers: Response headers (e.g. `httpx.Headers`, case-insensitive).
    :return: Parsed `UsageSnapshot`; any missing/unparseable header becomes `None`.
    """
    return UsageSnapshot(
        s5h_util=_as_float(headers.get(_5H_UTIL_HEADER)),
        s5h_status=headers.get(_5H_STATUS_HEADER),
        s5h_reset=_as_int(headers.get(_5H_RESET_HEADER)),
        s7d_util=_as_float(headers.get(_7D_UTIL_HEADER)),
        s7d_reset=_as_int(headers.get(_7D_RESET_HEADER)),
    )


def pct(util: float | None) -> int | None:
    """Convert a 0..1 utilization fraction to a rounded percentage.

    :param util: Utilization fraction, or `None` when unavailable.
    :return: Rounded percentage (0-100), or `None`.
    """
    return None if util is None else round(util * 100)


def bar(pct: int | None, width: int = 10) -> str:
    """Render a filled/empty Unicode block bar for a percentage.

    :param pct: Percentage (0-100), or `None` (renders fully empty).
    :param width: Total bar width in characters.
    :return: A `width`-character string of `█` (filled) and `░` (empty).
    """
    if pct is None:
        return "░" * width
    clamped = min(max(pct, 0), 100)
    filled = clamped * width // 100
    return "█" * filled + "░" * (width - filled)


def _as_float(value: str | None) -> float | None:
    """Parse a header string as a `float`.

    :param value: Raw header value, or `None`.
    :return: Parsed float, or `None` if absent/unparseable.
    """
    if value is None:
        return None
    try:
        return float(value)
    except ValueError:
        return None


def _as_int(value: str | None) -> int | None:
    """Parse a header string as an `int`.

    :param value: Raw header value, or `None`.
    :return: Parsed int, or `None` if absent/unparseable.
    """
    if value is None:
        return None
    try:
        return int(value)
    except ValueError:
        return None
