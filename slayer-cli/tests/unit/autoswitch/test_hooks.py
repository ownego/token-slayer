"""Tests for `autoswitch.hooks` — the TS_WRAPPED-gated hook bodies invoked
via `token-slayer hook <sub>`."""
from __future__ import annotations

import io
import json

from slayer_cli.autoswitch import hooks, signals
from slayer_cli.platform.paths import Paths


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
    """session_start writes SESSION_STARTED with sessionId + cwd."""
    pid = 1111
    _env(monkeypatch, tmp_path, pid)
    hooks.session_start(
        io.StringIO(json.dumps({"session_id": "s1", "cwd": "/work/dir"})), io.StringIO()
    )
    sig = signals.read(Paths("token_slayer"), pid, signals.SESSION_STARTED)
    assert sig == {"sessionId": "s1", "cwd": "/work/dir"}


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


def test_stop_tolerates_empty_stdin(tmp_path, monkeypatch):
    """Wrapped but empty/garbage stdin must not raise; no signal is written."""
    pid = 55
    _env(monkeypatch, tmp_path, pid)
    hooks.stop(io.StringIO(""))
    assert signals.read(Paths("token_slayer"), pid, signals.STOPPED) is None


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


def test_prompt_submit_ts_prefix_hints_inline(tmp_path, monkeypatch):
    """prompt_submit on `/ts:status` writes an inline hint to stdout, no signal, no token."""
    pid = 11
    _env(monkeypatch, tmp_path, pid)
    out = io.StringIO()
    hooks.prompt_submit(io.StringIO(json.dumps({"prompt": "/ts:status"})), out)
    assert signals.read(Paths("token_slayer"), pid, signals.SWITCH_REQUESTED) is None
    assert "token-slayer status" in out.getvalue()
    assert "sk-ant" not in out.getvalue()


def test_prompt_submit_passthrough_for_other_text(tmp_path, monkeypatch):
    """prompt_submit on ordinary text does nothing (no signal, no stdout noise)."""
    pid = 12
    _env(monkeypatch, tmp_path, pid)
    out = io.StringIO()
    hooks.prompt_submit(io.StringIO(json.dumps({"prompt": "hello there"})), out)
    assert signals.read(Paths("token_slayer"), pid, signals.SWITCH_REQUESTED) is None
    assert out.getvalue() == ""
