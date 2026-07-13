"""Tests for the `token-slayer run` wrapper driver (autoswitch.wrapper).

The full subprocess loop is heavy, so these tests inject a fake `spawn` and
a fake child process object exposing just `.poll()`/`.terminate()`/`.wait()`/
`.returncode`, and drive the loop through one real iteration: a pre-written
STOPPED signal triggers a live `decide_action` call (an over-threshold active
account + a healthy "b" candidate), which the wrapper must act on by calling
`switch_to` and relaunching with `--resume <session>`.
"""
from __future__ import annotations

import os

from slayer_cli.accounts.store import AccountStore
from slayer_cli.autoswitch import registry, signals, wrapper
from slayer_cli.cli.main import Services
from slayer_cli.config import store as config_store
from slayer_cli.config.model import Config, StrategyConfig
from slayer_cli.models.account import Account
from slayer_cli.models.usage_windows import AccountUsage, Window
from slayer_cli.platform.paths import Paths
from slayer_cli.usage import cache as usage_cache


class _FakeProc:
    """A minimal `subprocess.Popen`-shaped fake.

    Stays "alive" (`.poll()` returns None) either indefinitely
    (`auto_exit_after_polls=None`, until the wrapper calls `.terminate()` +
    `.wait()`) or until it has been polled more than `auto_exit_after_polls`
    times, then reports exited with `exit_code` on its own (simulating
    `claude` quitting normally with no pending signal).
    """

    def __init__(self, auto_exit_after_polls: int | None, exit_code: int = 0):
        self._auto_exit_after_polls = auto_exit_after_polls
        self._exit_code = exit_code
        self._poll_count = 0
        self.returncode = None
        self.terminated = False
        self.killed = False

    def poll(self):
        self._poll_count += 1
        if (self.returncode is None and self._auto_exit_after_polls is not None
                and self._poll_count > self._auto_exit_after_polls):
            self.returncode = self._exit_code
        return self.returncode

    def terminate(self):
        self.terminated = True

    def kill(self):
        self.killed = True

    def wait(self, timeout=None):
        self.returncode = self._exit_code
        return self._exit_code


def _fake_spawn(procs: list[_FakeProc]):
    """Return a `spawn`-shaped callable that yields `procs` in order and
    records every call's argv/env."""
    calls: list[dict] = []

    def spawn(argv, env=None):
        calls.append({"argv": list(argv), "env": dict(env or {})})
        return procs.pop(0)

    spawn.calls = calls
    return spawn


def _setup_accounts(paths: Paths) -> AccountStore:
    """Two slots, `a` active, `b` a healthy switch target."""
    store = AccountStore(paths)
    store.add(Account(name="a", email="a@x.com", org_uuid="oa", uuid="ua", plan=None,
                       token="sk-ant-oat01-AAA", refresh_token="ort01-aaa",
                       expires_at=9_999_999_999_999, added_at=1))
    store.add(Account(name="b", email="b@x.com", org_uuid="ob", uuid="ub", plan=None,
                       token="sk-ant-oat01-BBB", refresh_token="ort01-bbb",
                       expires_at=9_999_999_999_999, added_at=1))
    store.set_active("a")
    return store


def test_run_switches_and_relaunches_with_resume_on_stopped_over_threshold(tmp_path, monkeypatch, capsys):
    """A STOPPED signal with the active account over threshold drives one full
    decide→terminate→switch→relaunch iteration, then the child exits clean
    with no pending action and `run` returns its exit code."""
    monkeypatch.setenv("HOME", str(tmp_path))
    paths = Paths("token_slayer")
    store = _setup_accounts(paths)

    cfg = Config(strategy=StrategyConfig(kind="balanced"))
    config_store.save(paths, cfg)

    # "b" is a healthy switch target in the usage cache.
    healthy = AccountUsage(five_hour=Window(utilization=5.0), seven_day=Window(utilization=5.0), polled_at=1)
    usage_cache.save_cache(paths, {usage_cache.cache_key(store.get("b")): healthy})

    pid = os.getpid()
    signals.write(paths, pid, signals.SESSION_STARTED, {"sessionId": "sess-1", "cwd": str(tmp_path)})
    signals.write(paths, pid, signals.STOPPED, {"sessionId": "sess-1"})

    monkeypatch.setattr("slayer_cli.credstore.refresh.is_expired", lambda block, **kw: False)

    over_threshold = AccountUsage(
        five_hour=Window(utilization=50.0), seven_day=Window(utilization=100.0), polled_at=2)
    monkeypatch.setattr("slayer_cli.usage.oauth.fetch_usage", lambda token, **kw: over_threshold)

    switch_calls = []

    def fake_switch_to(store_arg, name, *, paths, force=False):
        switch_calls.append(name)
        return store_arg.get(name)

    monkeypatch.setattr("slayer_cli.autoswitch.wrapper.switch_to", fake_switch_to)

    proc1 = _FakeProc(auto_exit_after_polls=None)      # stays alive until the wrapper terminates it
    proc2 = _FakeProc(auto_exit_after_polls=0)         # relaunch exits immediately, no pending action
    spawn = _fake_spawn([proc1, proc2])

    services = Services(paths=paths, store=store)
    code = wrapper.run("claude", ["--flag"], services, spawn=spawn)

    assert code == 0
    assert switch_calls == ["b"]
    assert proc1.terminated is True

    assert len(spawn.calls) == 2
    first_env = spawn.calls[0]["env"]
    assert first_env["TS_WRAPPED"] == "1"
    assert first_env["TS_WRAPPER_PID"] == str(pid)

    relaunch_argv = spawn.calls[1]["argv"]
    assert "--resume" in relaunch_argv
    assert "sess-1" in relaunch_argv

    assert registry.list(paths) == []          # cleaned up on exit
    assert signals.read(paths, pid, signals.STOPPED) is None  # consumed

    captured = capsys.readouterr()
    for call in spawn.calls:
        joined = " ".join(str(x) for x in call["argv"]) + " ".join(str(v) for v in call["env"].values())
        assert "sk-ant" not in joined
    assert "sk-ant" not in captured.out
    assert "sk-ant" not in captured.err


def test_run_returns_child_exit_code_with_no_pending_action(tmp_path, monkeypatch):
    """No signals pending → the wrapper never decides an action; when the
    child exits it just cleans up the registry/signal bus and returns the
    child's exit code (user quit `claude` normally)."""
    monkeypatch.setenv("HOME", str(tmp_path))
    paths = Paths("token_slayer")
    store = _setup_accounts(paths)

    proc = _FakeProc(auto_exit_after_polls=0, exit_code=7)
    spawn = _fake_spawn([proc])

    services = Services(paths=paths, store=store)
    code = wrapper.run("claude", [], services, spawn=spawn)

    assert code == 7
    assert len(spawn.calls) == 1
    assert registry.list(paths) == []
