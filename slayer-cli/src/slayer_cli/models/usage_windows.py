"""Structured per-window usage from GET /api/oauth/usage, plus threshold logic.
A missing (None) window means 'no data, no decision' — never 'under threshold'."""
from __future__ import annotations
import time
from pydantic import BaseModel


class Window(BaseModel):
    utilization: float          # 0.0–100.0
    resets_at: int | None = None  # unix seconds, or None if the API returned null


class AccountUsage(BaseModel):
    five_hour: Window | None = None
    seven_day: Window | None = None
    seven_day_opus: Window | None = None
    seven_day_sonnet: Window | None = None
    polled_at: int = 0
    token_expired: bool = False


class Thresholds(BaseModel):
    five_hour: int = 100
    seven_day: int = 100


def is_over_threshold(u: AccountUsage, t: Thresholds) -> tuple[bool, str]:
    """Return (over, reason). Utilization >= 100 always counts (hard limit),
    regardless of the configured threshold. A threshold of 100 is 'reactive
    only' — no preemptive trigger below the hard limit. Missing windows never
    trigger.

    :param u: The account's usage snapshot.
    :param t: Configured thresholds.
    :return: (is_over, human-readable reason).
    """
    if u.five_hour is not None and u.five_hour.utilization >= 100:
        return True, "5h utilization at hard limit (100%)"
    if u.seven_day is not None and u.seven_day.utilization >= 100:
        return True, "7d utilization at hard limit (100%)"
    if 0 < t.seven_day < 100 and u.seven_day is not None and u.seven_day.utilization >= t.seven_day:
        return True, f"7d utilization {u.seven_day.utilization:.0f}% >= threshold {t.seven_day}%"
    if 0 < t.five_hour < 100 and u.five_hour is not None and u.five_hour.utilization >= t.five_hour:
        return True, f"5h utilization {u.five_hour.utilization:.0f}% >= threshold {t.five_hour}%"
    return False, ""


def now_seconds() -> int:
    """Current unix time in seconds (indirection so tests can monkeypatch)."""
    return int(time.time())
