"""The `token-slayer run` driver: spawns `claude`, polls the hook signal bus,
decides via the pure `decide_action`, and relaunches with `--resume` on any
pending switch/retry/wait. Kept THIN — every decision lives in `decide.py`;
this module is subprocess + signal-bus glue only.
"""
from __future__ import annotations

import os
import subprocess
import sys
import time
from typing import Callable

from slayer_cli.accounts.switch import switch_to
from slayer_cli.autoswitch import registry, relaunch, signals
from slayer_cli.autoswitch.decide import Action, decide_action
from slayer_cli.config import store as config_store
from slayer_cli.config.model import Config
from slayer_cli.models.usage_windows import is_over_threshold, now_seconds
from slayer_cli.strategy.recover import recover_soonest
from slayer_cli.strategy.select import Candidate
from slayer_cli.usage import cache as usage_cache
from slayer_cli.usage import poller

__all__ = ["run"]

#: How often the poll loop checks the signal bus, in seconds.
POLL_INTERVAL = 0.25

#: How long to wait for a graceful child exit after `terminate()` before `kill()`.
TERMINATE_TIMEOUT = 10.0

#: Decision signals, checked every poll cycle in `decide_action`'s priority order.
_DECISION_SIGNALS = (signals.RATE_LIMITED, signals.TURN_FAILED, signals.SWITCH_REQUESTED, signals.STOPPED)

#: Signals that may pick a NEW account (so all candidates' usage must be fresh).
#: TURN_FAILED is excluded — it retries the same account, never picks.
_PICK_SIGNALS = (signals.RATE_LIMITED, signals.SWITCH_REQUESTED, signals.STOPPED)


def _spawn_env(wrapper_pid: int) -> dict[str, str]:
    """Build the child `claude` process's environment.

    :param wrapper_pid: This wrapper's own PID, so hooks know where on the
        signal bus to write (`{TS_WRAPPER_PID}-{signal}` files).
    :return: A copy of the current environment plus the wrapped-session gate.
    """
    return {**os.environ, "TS_WRAPPED": "1", "TS_WRAPPER_PID": str(wrapper_pid)}


def _load_candidates(services) -> tuple[list[Candidate], Candidate | None, dict]:
    """Build strategy candidates for every managed slot plus the current usage cache.

    :param services: the CLI Services bundle (paths + store).
    :return: (candidates, current, cache).
    """
    accounts = services.store.list()
    candidates = [usage_cache.candidate_for(a) for a in accounts]
    active_name = services.store.active()
    current = next((c for c in candidates if c.name == active_name), None)
    cache = usage_cache.load_cache(services.paths)
    return candidates, current, cache


def _active_over_threshold(services, cfg: Config, cache: dict) -> bool:
    """Whether the active account's cached usage is over its configured threshold.

    :param services: the CLI Services bundle (paths + store).
    :param cfg: user behaviour configuration.
    :param cache: the usage cache (cache-key → AccountUsage).
    :return: True if the active account is over threshold; False when there is
        no active account or it has no cached usage.
    """
    store = services.store
    active_name = store.active()
    if not active_name or not store.exists(active_name):
        return False
    usage = cache.get(usage_cache.cache_key(store.get(active_name)))
    if usage is None:
        return False
    return bool(is_over_threshold(usage, cfg.thresholds)[0])


def _dir_of(transcript_path: str | None) -> str | None:
    """Return the directory holding a transcript file, or None when absent.

    :param transcript_path: An absolute `*.jsonl` transcript path, or None.
    :return: The containing directory, or None.
    """
    return os.path.dirname(transcript_path) if transcript_path else None


def _poll_once(services, cfg: Config, wrapper_pid: int, session_id: str | None,
                transcript_dir: str | None) -> tuple["Action | None", str | None, str | None]:
    """Check the signal bus once.

    Tracks `SESSION_STARTED`'s session id and transcript directory
    unconditionally (it never itself yields an Action), then looks for a
    decision signal in `decide_action`'s priority order; the first one present
    is consumed and decided. A signal that may pick a new account
    (`_PICK_SIGNALS`) refreshes usage for ALL candidates first (active via the
    live grant; non-active safely refreshed, TTL-gated) so the strategy picks a
    genuinely-available target; other signals act on the existing cache. The
    transcript directory is threaded so a relaunch can recover a missing
    session id from the newest transcript (see `run`).

    :param services: the CLI Services bundle (paths + store).
    :param cfg: user behaviour configuration.
    :param wrapper_pid: this wrapper's own PID (the signal-bus namespace).
    :param session_id: the currently-tracked session id, or None.
    :param transcript_dir: the currently-tracked transcript directory, or None.
    :return: (a non-`none` Action, or None; the session id; the transcript dir).
    """
    paths = services.paths

    started = signals.read(paths, wrapper_pid, signals.SESSION_STARTED)
    if started is not None:
        signals.consume(paths, wrapper_pid, signals.SESSION_STARTED)
        session_id = started.get("sessionId") or session_id
        transcript_dir = _dir_of(started.get("transcriptPath")) or transcript_dir

    for name in _DECISION_SIGNALS:
        payload = signals.read(paths, wrapper_pid, name)
        if payload is None:
            continue
        signals.consume(paths, wrapper_pid, name)

        if name == signals.STOPPED:
            session_id = payload.get("sessionId") or session_id
            transcript_dir = _dir_of(payload.get("transcriptPath")) or transcript_dir

        candidates, current, _ = _load_candidates(services)
        if name in _PICK_SIGNALS:
            cache = poller.refresh_all_usage(services.store, paths, now_seconds())
        else:
            cache = usage_cache.load_cache(paths)
        active_over_threshold = _active_over_threshold(services, cfg, cache)

        action = decide_action(pending_signal=name, signal_payload=payload, cfg=cfg,
                                active_over_threshold=active_over_threshold,
                                candidates=candidates, current=current, cache=cache)
        if action.kind != "none":
            return action, session_id, transcript_dir
    return None, session_id, transcript_dir


def _mark_swapping(entry: dict, target: str | None) -> None:
    """Set a registry entry's state to "swapping" against a switch target.

    :param entry: the mutable registry entry dict passed by `update_self`.
    :param target: the account name being switched to, or None.
    :return: None
    """
    entry["state"] = "swapping"
    entry["account"] = target


def _terminate(proc, *, timeout: float = TERMINATE_TIMEOUT) -> None:
    """Gracefully stop `proc`: `terminate()` then `wait(timeout)`, `kill()` on timeout.

    :param proc: The subprocess handle (or fake, in tests).
    :param timeout: Seconds to wait for a graceful exit before killing.
    :return: None
    """
    if proc.poll() is not None:
        return
    proc.terminate()
    try:
        proc.wait(timeout=timeout)
    except subprocess.TimeoutExpired:
        proc.kill()
        proc.wait()


def _apply_action(services, cfg: Config, action: Action) -> None:
    """Apply a pending non-`none` Action: switch, retry the same account, or
    wait for the soonest reset. Updates the session registry's state for
    each.

    A "switch" action's `switch_to` call can raise `SlayerError` (e.g.
    `AccountNotFound`) — this function does not catch it, so callers must
    handle the failure (the wrapper's poll loop falls back to relaunching on
    the current account rather than propagating and stranding the user).

    :param services: the CLI Services bundle (paths + store).
    :param cfg: user behaviour configuration.
    :param action: the decided Action.
    :return: None
    """
    paths = services.paths
    if action.kind == "switch":
        registry.update_self(paths, lambda e: _mark_swapping(e, action.target))
        switch_to(services.store, action.target, paths=paths)
    elif action.kind == "retry_same":
        registry.update_self(paths, lambda e: e.__setitem__("state", "retrying"))
    elif action.kind == "wait":
        registry.update_self(paths, lambda e: e.__setitem__("state", "waiting-reset"))
        candidates, _, cache = _load_candidates(services)
        recovery = recover_soonest(candidates, cache, cfg.thresholds, now=now_seconds())
        if recovery is not None:
            time.sleep(max(0.0, recovery.available_at - now_seconds()))


def run(claude_bin: str, argv: list[str], services, *, spawn: Callable = subprocess.Popen) -> int:
    """Drive `claude` under auto-switch: spawn, poll signals, decide, switch, relaunch.

    Registers this process in the session registry for the run's duration,
    spawns `claude` with `TS_WRAPPED=1`/`TS_WRAPPER_PID` so its hooks write
    to this wrapper's signal-bus namespace, then loops: poll the bus every
    ~0.25s while the child is alive; on a decision signal that yields a
    non-`none` Action, terminate the child gracefully, apply the action
    (switch / retry-same / wait-for-reset), and relaunch with `--resume`
    (never re-sending the failed turn — `relaunch_argv` resumes the
    session, it never re-POSTs). If applying the action raises (e.g. a
    "switch" target rejected with `AccountNotFound`), a token-free warning is
    printed to stderr and the wrapper still relaunches on the CURRENT
    account rather than stranding the user with no running child. Exits when
    the child quits with no pending action (the user quit `claude`).

    :param claude_bin: Absolute path to the `claude` executable.
    :param argv: Arguments to pass to `claude` (already split at the CLI's `--`).
    :param services: the CLI Services bundle (paths + store).
    :param spawn: Injectable process spawner (`subprocess.Popen`-compatible
        callable taking `(argv, env=...)`); tests inject a fake.
    :return: The final child process's exit code.
    """
    paths = services.paths
    wrapper_pid = os.getpid()
    cfg = config_store.load(paths)

    registry.update_self(paths, lambda e: e.__setitem__("state", "running"))
    session_id: str | None = None
    transcript_dir: str | None = None
    retry_count = 0
    proc = spawn([claude_bin, *argv], env=_spawn_env(wrapper_pid))

    while True:
        pending: Action | None = None
        # This wrapper targets INTERACTIVE `claude` sessions: the Stop hook
        # fires on turn-end while the child stays alive, so the poll loop
        # below always gets a chance to observe the signal. A `-p`/single-shot
        # child that exits immediately after writing a signal can race this
        # loop and lose that final signal (a known, accepted v1 limitation —
        # single-shot auto-switch is out of scope).
        while proc.poll() is None:
            pending, session_id, transcript_dir = _poll_once(
                services, cfg, wrapper_pid, session_id, transcript_dir)
            if pending is not None:
                break
            time.sleep(POLL_INTERVAL)

        if pending is None:
            break  # the child exited on its own — the user quit claude.

        _terminate(proc)
        try:
            _apply_action(services, cfg, pending)
        except Exception as exc:
            # Never strand the user: a failed switch (e.g. AccountNotFound)
            # still relaunches on the CURRENT account rather than leaving no
            # child running. `exc` is a SlayerError message and never
            # contains a token, but nothing token-bearing is interpolated here.
            print(f"token-slayer: switch failed ({exc}); continuing on the current account", file=sys.stderr)

        # Space out repeated turn-failure retries with fibonacci backoff so a
        # sustained API outage doesn't become a terminate/relaunch storm; any
        # non-retry action (switch/wait) resets the escalation.
        if pending.kind == "retry_same":
            time.sleep(relaunch.fibonacci_delay(retry_count))
            retry_count += 1
        else:
            retry_count = 0

        # Recover a missing session id from the transcript directory so the
        # relaunch --resumes the same conversation instead of starting fresh
        # (and losing context) when no signal carried a sessionId.
        if session_id is None and transcript_dir is not None:
            session_id = relaunch.session_id_from("", transcript_dir)

        resume_message = pending.resume_message or cfg.auto_message
        argv = relaunch.relaunch_argv(argv, session_id, auto_resume=cfg.auto_resume, auto_message=resume_message)
        registry.update_self(paths, lambda e: e.__setitem__("state", "running"))
        proc = spawn([claude_bin, *argv], env=_spawn_env(wrapper_pid))

    registry.remove_self(paths)
    signals.cleanup_for_pid(paths, wrapper_pid)
    return proc.returncode
