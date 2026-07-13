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
    assert act.kind == "retry_same"


def test_manual_switch_request_uses_explicit_target():
    act = decide_action(pending_signal=signals.SWITCH_REQUESTED, signal_payload={"target": "b"},
                        cfg=_cfg(strategy=StrategyConfig(kind="manual")), active_over_threshold=False,
                        candidates=[A, B], current=A, cache=_cache)
    assert act.kind == "switch" and act.target == "b"
