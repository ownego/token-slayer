"""Tests for `autoswitch.hooks` — the TS_WRAPPED-gated hook bodies invoked
via `token-slayer hook <sub>`."""
from __future__ import annotations

import io
import json

from slayer_cli.accounts.store import AccountStore
from slayer_cli.autoswitch import hooks, signals
from slayer_cli.config import store as config_store
from slayer_cli.models.account import Account
from slayer_cli.models.usage_windows import AccountUsage, Window
from slayer_cli.platform.paths import Paths
from slayer_cli.usage import cache as usage_cache


def _env(monkeypatch, tmp_path, pid):
    """Wire up a wrapped-session environment for a hook test.

    :param monkeypatch: pytest monkeypatch fixture.
    :param tmp_path: pytest tmp_path fixture, used as HOME.
    :param pid: The wrapper PID to publish via TS_WRAPPER_PID.
    :return: None
    """
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.setenv("TS_WRAPPED", "1")
    monkeypatch.setenv("TS_WRAPPER_PID", str(pid))


def test_session_start_writes_signal(tmp_path, monkeypatch):
    """session_start writes SESSION_STARTED with sessionId + cwd + transcriptPath."""
    pid = 1111
    _env(monkeypatch, tmp_path, pid)
    hooks.session_start(
        io.StringIO(json.dumps({"session_id": "s1", "cwd": "/work/dir", "transcript_path": "/t/s1.jsonl"})),
        io.StringIO(),
    )
    sig = signals.read(Paths("token_slayer"), pid, signals.SESSION_STARTED)
    assert sig == {"sessionId": "s1", "cwd": "/work/dir", "transcriptPath": "/t/s1.jsonl"}


def test_stop_captures_transcript_path(tmp_path, monkeypatch):
    """stop carries transcriptPath so the wrapper can recover a missing sessionId."""
    pid = 4243
    _env(monkeypatch, tmp_path, pid)
    hooks.stop(io.StringIO(json.dumps({"session_id": "s1", "transcript_path": "/t/s1.jsonl"})))
    sig = signals.read(Paths("token_slayer"), pid, signals.STOPPED)
    assert sig["transcriptPath"] == "/t/s1.jsonl"


def test_stop_writes_signal(tmp_path, monkeypatch):
    """stop writes STOPPED with sessionId."""
    pid = 4242
    _env(monkeypatch, tmp_path, pid)
    hooks.stop(io.StringIO(json.dumps({"session_id": "s1"})))
    assert signals.read(Paths("token_slayer"), pid, signals.STOPPED)["sessionId"] == "s1"


def test_unwrapped_is_noop(tmp_path, monkeypatch):
    """With TS_WRAPPED unset, hooks must not raise and must not write a signal."""
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("TS_WRAPPED", raising=False)
    hooks.stop(io.StringIO('{"session_id":"s1"}'))  # must not raise, must not write


def test_unwrapped_empty_stdin_is_noop(tmp_path, monkeypatch):
    """Unwrapped with empty stdin still must not raise (tolerant parsing)."""
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("TS_WRAPPED", raising=False)
    hooks.session_start(io.StringIO(""), io.StringIO())
    hooks.rate_limit(io.StringIO(""))
    hooks.prompt_submit(io.StringIO(""), io.StringIO())


def test_stop_writes_even_on_empty_stdin(tmp_path, monkeypatch):
    """Wrapped with empty stdin writes STOPPED signal (presence=event; sessionId is None)."""
    pid = 55
    _env(monkeypatch, tmp_path, pid)
    hooks.stop(io.StringIO(""))
    sig = signals.read(Paths("token_slayer"), pid, signals.STOPPED)
    assert sig is not None
    assert sig.get("sessionId") is None


def test_rate_limit_classifies(tmp_path, monkeypatch):
    """rate_limit classifies via classify_failure and writes RATE_LIMITED."""
    pid = 7
    _env(monkeypatch, tmp_path, pid)
    hooks.rate_limit(
        io.StringIO(
            json.dumps({"hook_event_name": "PostToolUseFailure", "error": "rate_limit_error"})
        )
    )
    assert signals.read(Paths("token_slayer"), pid, signals.RATE_LIMITED) is not None


def test_rate_limit_writes_turn_failed(tmp_path, monkeypatch):
    """rate_limit writes TURN_FAILED for StopFailure + API-error patterns."""
    pid = 8
    _env(monkeypatch, tmp_path, pid)
    hooks.rate_limit(
        io.StringIO(
            json.dumps({"hook_event_name": "StopFailure", "error": "internal server error"})
        )
    )
    assert signals.read(Paths("token_slayer"), pid, signals.TURN_FAILED) is not None
    assert signals.read(Paths("token_slayer"), pid, signals.RATE_LIMITED) is None


def test_rate_limit_no_signal_for_benign_text(tmp_path, monkeypatch):
    """rate_limit writes nothing when classify_failure finds no pattern."""
    pid = 13
    _env(monkeypatch, tmp_path, pid)
    hooks.rate_limit(
        io.StringIO(json.dumps({"hook_event_name": "Stop", "error": "all good"}))
    )
    assert signals.read(Paths("token_slayer"), pid, signals.RATE_LIMITED) is None
    assert signals.read(Paths("token_slayer"), pid, signals.TURN_FAILED) is None


def test_rate_limit_falls_back_to_error_details_and_last_assistant_message(tmp_path, monkeypatch):
    """rate_limit reads error_details/last_assistant_message when error is absent."""
    pid = 14
    _env(monkeypatch, tmp_path, pid)
    hooks.rate_limit(
        io.StringIO(
            json.dumps({"hook_event_name": "PostToolUseFailure", "error_details": "rate_limit_error"})
        )
    )
    assert signals.read(Paths("token_slayer"), pid, signals.RATE_LIMITED) is not None

    pid2 = 15
    _env(monkeypatch, tmp_path, pid2)
    hooks.rate_limit(
        io.StringIO(
            json.dumps(
                {"hook_event_name": "PostToolUseFailure", "last_assistant_message": "rate_limit_error"}
            )
        )
    )
    assert signals.read(Paths("token_slayer"), pid2, signals.RATE_LIMITED) is not None


def test_prompt_submit_switch(tmp_path, monkeypatch):
    """prompt_submit on `/switch work` writes SWITCH_REQUESTED{target: work}."""
    pid = 9
    _env(monkeypatch, tmp_path, pid)
    out = io.StringIO()
    hooks.prompt_submit(io.StringIO(json.dumps({"prompt": "/switch work"})), out)
    sig = signals.read(Paths("token_slayer"), pid, signals.SWITCH_REQUESTED)
    assert sig["target"] == "work"
    assert "sk-ant" not in out.getvalue()


def test_prompt_submit_switch_no_target_is_empty(tmp_path, monkeypatch):
    """prompt_submit on bare `/switch` writes SWITCH_REQUESTED{target: ""} (rotate)."""
    pid = 10
    _env(monkeypatch, tmp_path, pid)
    out = io.StringIO()
    hooks.prompt_submit(io.StringIO(json.dumps({"prompt": "/switch"})), out)
    sig = signals.read(Paths("token_slayer"), pid, signals.SWITCH_REQUESTED)
    assert sig["target"] == ""


def test_prompt_submit_ts_unknown_cmd_hints_inline(tmp_path, monkeypatch):
    """prompt_submit on an unrecognized `/ts:<cmd>` falls back to the inline hint."""
    pid = 11
    _env(monkeypatch, tmp_path, pid)
    out = io.StringIO()
    hooks.prompt_submit(io.StringIO(json.dumps({"prompt": "/ts:frobnicate"})), out)
    assert signals.read(Paths("token_slayer"), pid, signals.SWITCH_REQUESTED) is None
    assert "token-slayer frobnicate" in out.getvalue()
    assert "sk-ant" not in out.getvalue()


def _seed_account(paths: Paths, name: str, *, active: bool = False) -> Account:
    """Add an account slot (with a fake token) to `paths`' AccountStore.

    :param paths: Resolved OS paths for this namespace.
    :param name: Slot name.
    :param active: Whether to mark this slot active.
    :return: The seeded Account.
    """
    account = Account(
        name=name, email=f"{name}@example.com", org_uuid=f"org-{name}", uuid=f"uuid-{name}",
        plan=None, token="sk-ant-oat01-TESTTOKEN", added_at=1, last_used=None,
    )
    store = AccountStore(paths)
    store.add(account)
    if active:
        store.set_active(name)
    return account


def test_prompt_submit_ts_list_renders_accounts_no_token(tmp_path, monkeypatch):
    """`/ts:list` renders account names/usage inline, writes no signal, never a token."""
    pid = 20
    _env(monkeypatch, tmp_path, pid)
    paths = Paths("token_slayer")
    _seed_account(paths, "work", active=True)
    _seed_account(paths, "personal")

    out = io.StringIO()
    hooks.prompt_submit(io.StringIO(json.dumps({"prompt": "/ts:list"})), out)

    rendered = out.getvalue()
    assert "work" in rendered
    assert "personal" in rendered
    assert "sk-ant" not in rendered
    assert signals.read(Paths("token_slayer"), pid, signals.SWITCH_REQUESTED) is None


def test_prompt_submit_ts_list_includes_cached_usage(tmp_path, monkeypatch):
    """`/ts:list` surfaces cached utilization percentages when the usage cache is populated."""
    pid = 21
    _env(monkeypatch, tmp_path, pid)
    paths = Paths("token_slayer")
    account = _seed_account(paths, "work", active=True)
    usage_cache.save_cache(
        paths,
        {usage_cache.cache_key(account): AccountUsage(five_hour=Window(utilization=42.0), seven_day=Window(utilization=18.0))},
    )

    out = io.StringIO()
    hooks.prompt_submit(io.StringIO(json.dumps({"prompt": "/ts:usage"})), out)

    rendered = out.getvalue()
    assert "42" in rendered
    assert "18" in rendered
    assert "sk-ant" not in rendered


def test_prompt_submit_ts_list_no_accounts_is_friendly(tmp_path, monkeypatch):
    """`/ts:status` with no account slots renders a friendly message, not a crash."""
    pid = 22
    _env(monkeypatch, tmp_path, pid)
    out = io.StringIO()
    hooks.prompt_submit(io.StringIO(json.dumps({"prompt": "/ts:status"})), out)
    rendered = out.getvalue()
    assert rendered.strip() != ""
    assert "sk-ant" not in rendered


def test_prompt_submit_ts_config_renders_json(tmp_path, monkeypatch):
    """`/ts:config` renders the current config as JSON."""
    pid = 23
    _env(monkeypatch, tmp_path, pid)
    paths = Paths("token_slayer")
    cfg = config_store.load(paths)
    cfg = config_store.set_value(cfg, "strategy.kind", "balanced")
    config_store.save(paths, cfg)

    out = io.StringIO()
    hooks.prompt_submit(io.StringIO(json.dumps({"prompt": "/ts:config"})), out)

    rendered = out.getvalue()
    parsed = json.loads(rendered)
    assert parsed["strategy"]["kind"] == "balanced"
    assert "sk-ant" not in rendered


def test_prompt_submit_ts_switch_writes_signal(tmp_path, monkeypatch):
    """`/ts:switch <target>` behaves exactly like `/switch <target>`."""
    pid = 24
    _env(monkeypatch, tmp_path, pid)
    out = io.StringIO()
    hooks.prompt_submit(io.StringIO(json.dumps({"prompt": "/ts:switch work"})), out)
    sig = signals.read(Paths("token_slayer"), pid, signals.SWITCH_REQUESTED)
    assert sig == {"target": "work"}
    assert "sk-ant" not in out.getvalue()


def test_prompt_submit_passthrough_for_other_text(tmp_path, monkeypatch):
    """prompt_submit on ordinary text does nothing (no signal, no stdout noise)."""
    pid = 12
    _env(monkeypatch, tmp_path, pid)
    out = io.StringIO()
    hooks.prompt_submit(io.StringIO(json.dumps({"prompt": "hello there"})), out)
    assert signals.read(Paths("token_slayer"), pid, signals.SWITCH_REQUESTED) is None
    assert out.getvalue() == ""
