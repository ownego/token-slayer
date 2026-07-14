"""Unit tests for the pure reset-soon recovery strategy (recover_soonest)."""
from slayer_cli.strategy.select import Candidate, Pick
from slayer_cli.strategy.recover import recover_soonest, should_rebalance
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


def test_5h_only_wins_even_when_7d_blocked_resets_sooner():
    """A 5h-only block beats a 7d block even when 7d resets sooner.

    a: 5h-capped (resets in 1h), 7d healthy → only_five_hour, must win.
    b: 7d-capped (resets in 5 min), 5h healthy → loses despite earlier reset.
    """
    now = 1_700_000_000
    cache = {"a": _u(100, now + 3600, 20, now + 999999),
             "b": _u(40, now + 100, 97, now + 300)}
    rec = recover_soonest([Candidate("a", "a"), Candidate("b", "b")], cache,
                          Thresholds(five_hour=90, seven_day=95), now=now)
    assert rec.name == "a" and rec.only_five_hour is True


def test_should_rebalance_happy_path():
    """Drain mode: first healthy priority account is returned."""
    now = 1_700_000_000
    # a: priority account, healthy (under thresholds)
    # b: priority account, over threshold → should skip
    # current: a temp account not in priority order
    cache = {"a": _u(50, now + 3600, 40, now + 600000),
             "b": _u(95, now + 100, 98, now + 500000),
             "temp": _u(50, now + 3600, 40, now + 600000)}
    current = Candidate("temp", "temp")
    pick = should_rebalance(["a", "b"], [Candidate("a", "a"), Candidate("b", "b"), current],
                           current, cache, Thresholds(five_hour=90, seven_day=95))
    assert pick is not None and pick.name == "a"


def test_should_rebalance_none_when_empty_order():
    """No priority order → None."""
    cache = {"a": _u(50, 1000, 40, 2000)}
    pick = should_rebalance([], [Candidate("a", "a")], Candidate("a", "a"),
                           cache, Thresholds())
    assert pick is None


def test_should_rebalance_none_when_current_is_none():
    """Current account is None → None (not in drain mode)."""
    cache = {"a": _u(50, 1000, 40, 2000)}
    pick = should_rebalance(["a"], [Candidate("a", "a")], None,
                           cache, Thresholds())
    assert pick is None


def test_should_rebalance_skips_over_threshold():
    """First priority account is over threshold → skip to next healthy one."""
    now = 1_700_000_000
    # a: over threshold → skip
    # b: healthy → return this one
    cache = {"a": _u(95, now + 3600, 97, now + 600000),
             "b": _u(50, now + 100, 40, now + 500000),
             "temp": _u(50, now + 3600, 40, now + 600000)}
    current = Candidate("temp", "temp")
    pick = should_rebalance(["a", "b"], [Candidate("a", "a"), Candidate("b", "b"), current],
                           current, cache, Thresholds(five_hour=90, seven_day=95))
    assert pick is not None and pick.name == "b"
