"""Unit tests for the pure reset-soon recovery strategy (recover_soonest)."""
from slayer_cli.strategy.select import Candidate
from slayer_cli.strategy.recover import recover_soonest
from slayer_cli.models.usage_windows import AccountUsage, Window, Thresholds


def _u(f5, f5r, f7, f7r):
    """Build an AccountUsage with 5h/7d utilizations and reset times.

    :param f5: 5-hour window utilization.
    :param f5r: 5-hour window reset time (unix seconds).
    :param f7: 7-day window utilization.
    :param f7r: 7-day window reset time (unix seconds).
    :return: an AccountUsage.
    """
    return AccountUsage(five_hour=Window(utilization=f5, resets_at=f5r),
                        seven_day=Window(utilization=f7, resets_at=f7r), polled_at=1)


def test_prefers_5h_only_block_with_healthy_7d():
    """A: 5h-capped (resets in 8 min), 7d healthy → best recovery.
    B: 7d-nearly-capped (resets in days).
    """
    now = 1_700_000_000
    cache = {"a": _u(100, now + 480, 20, now + 600000),
             "b": _u(40, now + 100, 97, now + 500000)}
    rec = recover_soonest([Candidate("a", "a"), Candidate("b", "b")], cache,
                          Thresholds(five_hour=90, seven_day=95), now=now)
    assert rec.name == "a" and rec.only_five_hour is True


def test_none_when_no_reset_info():
    """No cache entry usable for recovery timing → None."""
    now = 1_700_000_000
    cache = {"a": AccountUsage(polled_at=1)}
    assert recover_soonest([Candidate("a", "a")], cache, Thresholds(), now=now) is None
