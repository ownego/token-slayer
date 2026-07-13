from slayer_cli.models.usage_windows import Window, AccountUsage, Thresholds, is_over_threshold


def _u(f5=None, f7=None):
    return AccountUsage(
        five_hour=Window(utilization=f5) if f5 is not None else None,
        seven_day=Window(utilization=f7) if f7 is not None else None,
        polled_at=1700000000)


def test_hard_limit_always_over_regardless_of_threshold():
    over, why = is_over_threshold(_u(f5=100.0), Thresholds())        # default 100 = reactive
    assert over and "5h" in why
    over, _ = is_over_threshold(_u(f7=100.0), Thresholds())
    assert over


def test_soft_threshold():
    assert is_over_threshold(_u(f7=96.0), Thresholds(seven_day=95))[0] is True
    assert is_over_threshold(_u(f7=90.0), Thresholds(seven_day=95))[0] is False


def test_nil_window_is_no_decision():
    assert is_over_threshold(_u(), Thresholds(five_hour=50, seven_day=50))[0] is False
