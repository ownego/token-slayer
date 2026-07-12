from slayer_cli.tui import format as fmt
from slayer_cli.usage.parser import bar


def test_full_bar_reuses_parser_bar_at_width_24():
    assert fmt.full_bar(50) == bar(50, 24)
    assert len(fmt.full_bar(50)) == 24


def test_full_bar_none_is_fully_empty():
    assert fmt.full_bar(None) == "░" * 24


def test_bar_color_thresholds():
    assert fmt.bar_color(0) == "green"
    assert fmt.bar_color(69) == "green"
    assert fmt.bar_color(70) == "yellow"
    assert fmt.bar_color(89) == "yellow"
    assert fmt.bar_color(90) == "bold red"
    assert fmt.bar_color(100) == "bold red"


def test_bar_color_none_is_dim():
    assert fmt.bar_color(None) == "dim"


def test_is_limited_true_only_for_limited_status():
    assert fmt.is_limited("limited") is True
    assert fmt.is_limited("allowed") is False
    assert fmt.is_limited(None) is False


def test_reset_countdown_none_is_em_dash():
    assert fmt.reset_countdown(None) == "—"


def test_reset_countdown_under_a_minute_is_now():
    assert fmt.reset_countdown(1_000_030, now=1_000_000) == "now"


def test_reset_countdown_minutes_only():
    assert fmt.reset_countdown(1_000_000 + 25 * 60, now=1_000_000) == "in 25m"


def test_reset_countdown_hours_and_minutes():
    assert fmt.reset_countdown(1_000_000 + 3 * 3600 + 15 * 60, now=1_000_000) == "in 3h 15m"


def test_reset_countdown_days_and_hours():
    assert fmt.reset_countdown(1_000_000 + 2 * 86400 + 5 * 3600, now=1_000_000) == "in 2d 5h"


def test_reset_clock_none_is_em_dash():
    assert fmt.reset_clock(None) == "—"


def test_reset_clock_formats_timestamp():
    # 1_700_000_000 is a fixed, well-known epoch second — just assert shape/format, not TZ-specific value.
    result = fmt.reset_clock(1_700_000_000)
    assert len(result) == 5 and result[2] == ":"


def test_reset_clock_accepts_custom_format():
    result = fmt.reset_clock(1_700_000_000, clock_format="%a")
    assert len(result) == 3
