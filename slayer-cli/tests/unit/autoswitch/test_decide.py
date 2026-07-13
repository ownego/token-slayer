from unittest import mock

from slayer_cli.autoswitch.decide import decide_action
from slayer_cli.autoswitch import signals
from slayer_cli.config.model import Config, StrategyConfig
from slayer_cli.strategy.select import Candidate
from slayer_cli.models.usage_windows import AccountUsage, Window

A, B = Candidate("a", "a"), Candidate("b", "b")
_cache = {"b": AccountUsage(five_hour=Window(utilization=5.0), seven_day=Window(utilization=10.0), polled_at=1)}


def _cfg(**kw):
    base = dict(strategy=StrategyConfig(kind="balanced"))
    base.update(kw)
    return Config(**base)


def test_rate_limit_switches():
    act = decide_action(pending_signal=signals.RATE_LIMITED, signal_payload={"message": "429"},
                        cfg=_cfg(), active_over_threshold=False, candidates=[A, B], current=A, cache=_cache)
    assert act.kind == "switch" and act.target == "b"


def test_threshold_switches_only_on_stopped_and_not_manual():
    act = decide_action(pending_signal=signals.STOPPED, signal_payload={}, cfg=_cfg(),
                        active_over_threshold=True, candidates=[A, B], current=A, cache=_cache)
    assert act.kind == "switch" and act.target == "b"
    manual = decide_action(pending_signal=signals.STOPPED, signal_payload={},
                           cfg=_cfg(strategy=StrategyConfig(kind="manual")),
                           active_over_threshold=True, candidates=[A, B], current=A, cache=_cache)
    assert manual.kind == "none"


def test_turn_failed_retries_same():
    act = decide_action(pending_signal=signals.TURN_FAILED, signal_payload={"message": "500"},
                        cfg=_cfg(), active_over_threshold=False, candidates=[A, B], current=A, cache=_cache)
    assert act.kind == "retry_same" and act.target is None


def test_manual_switch_request_uses_explicit_target():
    act = decide_action(pending_signal=signals.SWITCH_REQUESTED, signal_payload={"target": "b"},
                        cfg=_cfg(strategy=StrategyConfig(kind="manual")), active_over_threshold=False,
                        candidates=[A, B], current=A, cache=_cache)
    assert act.kind == "switch" and act.target == "b"


def test_rate_limit_falls_back_to_wait_when_none_pickable():
    now = 1_700_000_000
    # b is over threshold (5h at hard limit) but its 5h resets soon → recover_soonest returns b.
    cache = {"b": AccountUsage(five_hour=Window(utilization=100.0, resets_at=now + 300),
                               seven_day=Window(utilization=20.0, resets_at=now + 999999), polled_at=1)}
    cfg = _cfg(wait_for_reset=True)
    with mock.patch('slayer_cli.autoswitch.decide.now_seconds', return_value=now):
        act = decide_action(pending_signal=signals.RATE_LIMITED, signal_payload={"message": "429"},
                            cfg=cfg, active_over_threshold=False, candidates=[A, B], current=A, cache=cache)
    assert act.kind == "wait" and act.target == "b"


def test_rate_limit_falls_back_to_none_when_wait_for_reset_off():
    now = 1_700_000_000
    # b is over threshold but wait_for_reset is False → should return "none" instead of "wait".
    cache = {"b": AccountUsage(five_hour=Window(utilization=100.0, resets_at=now + 300),
                               seven_day=Window(utilization=20.0, resets_at=now + 999999), polled_at=1)}
    cfg = _cfg(wait_for_reset=False)
    with mock.patch('slayer_cli.autoswitch.decide.now_seconds', return_value=now):
        act = decide_action(pending_signal=signals.RATE_LIMITED, signal_payload={"message": "429"},
                            cfg=cfg, active_over_threshold=False, candidates=[A, B], current=A, cache=cache)
    assert act.kind == "none"


def test_rate_limit_off_is_none():
    act = decide_action(pending_signal=signals.RATE_LIMITED, signal_payload={},
                        cfg=_cfg(auto_switch_on_rate_limit=False), active_over_threshold=False,
                        candidates=[A, B], current=A, cache=_cache)
    assert act.kind == "none"


def test_threshold_off_is_none():
    act = decide_action(pending_signal=signals.STOPPED, signal_payload={},
                        cfg=_cfg(auto_switch_on_threshold=False), active_over_threshold=True,
                        candidates=[A, B], current=A, cache=_cache)
    assert act.kind == "none"


def test_retry_off_is_none():
    act = decide_action(pending_signal=signals.TURN_FAILED, signal_payload={},
                        cfg=_cfg(retry_on_api_error=False), active_over_threshold=False,
                        candidates=[A, B], current=A, cache=_cache)
    assert act.kind == "none"
