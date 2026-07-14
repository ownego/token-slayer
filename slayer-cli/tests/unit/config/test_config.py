import pytest
from slayer_cli.config import model, store
from slayer_cli.platform.paths import Paths


def test_defaults_manual(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    cfg = store.load(Paths("token_slayer"))          # missing file → defaults
    assert cfg.strategy.kind == "manual"
    assert cfg.thresholds.five_hour == 100 and cfg.thresholds.seven_day == 100
    assert cfg.auto_switch_on_threshold is True and cfg.auto_message == "continue"


def test_set_and_round_trip(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    cfg = store.load(p)
    cfg = store.set_value(cfg, "strategy.kind", "balanced")
    cfg = store.set_value(cfg, "thresholds.seven_day", "85")
    cfg = store.set_value(cfg, "auto_resume", "false")
    store.save(p, cfg)
    reloaded = store.load(p)
    assert reloaded.strategy.kind == "balanced" and reloaded.thresholds.seven_day == 85
    assert reloaded.auto_resume is False


def test_set_rejects_bad_values(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    cfg = store.load(Paths("token_slayer"))
    with pytest.raises(store.ConfigError):
        store.set_value(cfg, "strategy.kind", "nope")
    with pytest.raises(store.ConfigError):
        store.set_value(cfg, "thresholds.five_hour", "150")
    with pytest.raises(store.ConfigError):
        store.set_value(cfg, "unknown.key", "x")


def test_next_strategy_kind_cycles_manual_balanced_drain():
    assert store.next_strategy_kind("manual") == "balanced"
    assert store.next_strategy_kind("balanced") == "drain"
    assert store.next_strategy_kind("drain") == "manual"


def test_next_strategy_kind_resets_unknown_to_manual():
    assert store.next_strategy_kind("bogus") == "manual"
