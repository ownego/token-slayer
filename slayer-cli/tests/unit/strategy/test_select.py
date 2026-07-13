"""Unit tests for the pure account-selection strategy (pick_next)."""
from slayer_cli.strategy.select import Candidate, pick_next
from slayer_cli.models.usage_windows import AccountUsage, Window, Thresholds


def _u(f5, f7, opus=None):
    """Build an AccountUsage with the given 5h/7d/opus-7d utilizations.

    :param f5: 5-hour window utilization.
    :param f7: 7-day window utilization.
    :param opus: 7-day opus utilization, or None to omit the window.
    :return: an AccountUsage.
    """
    return AccountUsage(five_hour=Window(utilization=f5), seven_day=Window(utilization=f7),
                        seven_day_opus=Window(utilization=opus) if opus is not None else None, polled_at=1)


A, B, C = Candidate("a", "a"), Candidate("b", "b"), Candidate("c", "c")


def test_manual_never_picks():
    """Manual mode always returns None, regardless of candidates or cache."""
    assert pick_next("manual", [], [A, B], A, {"b": _u(10, 10)}, Thresholds()) is None


def test_balanced_lowest_7d():
    """Balanced mode picks the candidate with the lowest 7d utilization."""
    cache = {"b": _u(50, 10), "c": _u(20, 90)}
    assert pick_next("balanced", [], [A, B, C], A, cache, Thresholds()).name == "b"  # 7d 10 < 90


def test_excludes_the_99pct_weekly_trap():
    """A1 at 0%/99% must lose to A2 at 50%/10% (the reset-soon example from the spec)."""
    cache = {"b": _u(0, 99), "c": _u(50, 10)}
    assert pick_next("balanced", [], [A, B, C], A, cache, Thresholds(five_hour=90, seven_day=95)).name == "c"


def test_drain_auto_by_highest_7d_then_caps():
    """Drain mode with no explicit order auto-sorts by highest 7d (closest to cap first)."""
    cache = {"b": _u(10, 80), "c": _u(10, 50)}
    assert pick_next("drain", [], [A, B, C], A, cache, Thresholds()).name == "b"  # drain closest-to-cap first


def test_model_capped_sorts_last():
    """A model-capped (opus >= 100) candidate stays eligible but sorts after non-capped ones."""
    cache = {"b": _u(10, 10, opus=100), "c": _u(20, 20)}
    assert pick_next("balanced", [], [A, B, C], A, cache, Thresholds()).name == "c"  # b opus-capped -> last


def test_drain_model_capped_still_picked_when_only_option():
    """A drain pool where the only candidate with room is model-capped is still picked on the 2nd (non-model-clear) pass, rather than stranding the pool with None."""
    cache = {"b": _u(10, 10, opus=100)}
    assert pick_next("drain", [], [A, B], A, cache, Thresholds()).name == "b"
