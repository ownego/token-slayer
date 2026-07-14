"""The pure swap decision the wrapper drives.

Given the pending signal (from the file bus), the current config, and the
usage cache, decide what the wrapper should do next: switch accounts, retry
the same account, wait for a reset, or do nothing. No IO, no subprocess —
fully unit-testable.
"""
from __future__ import annotations
from dataclasses import dataclass
from slayer_cli.autoswitch import signals
from slayer_cli.config.model import Config
from slayer_cli.models.usage_windows import AccountUsage, now_seconds
from slayer_cli.strategy.recover import recover_soonest
from slayer_cli.strategy.select import Candidate, pick_next


@dataclass(frozen=True)
class Action:
    """The wrapper's next move.

    :var kind: one of "none", "switch", "retry_same", "wait".
    :var target: the account name to switch to or wait on, or None.
    :var resume_message: message to replay on resume, or None.
    :var reason: human-readable reason for the decision.
    """
    kind: str
    target: str | None
    resume_message: str | None
    reason: str


def _switch_or_wait(cfg: Config, candidates: list[Candidate], current: Candidate | None,
                     cache: dict[str, AccountUsage], reason: str,
                     resume_message: str | None = None) -> Action:
    """Pick the next account; fall back to the soonest-recovering one when
    nothing is immediately eligible.

    :param cfg: user behaviour configuration.
    :param candidates: all managed accounts.
    :param current: the active account, or None.
    :param cache: usage-cache-key → AccountUsage.
    :param reason: human-readable reason to attach to the resulting action.
    :param resume_message: message to replay on resume, or None.
    :return: a "switch" Action, a "wait" Action, or a "none" Action.
    """
    pick = pick_next(cfg.strategy.kind, cfg.strategy.order, candidates, current, cache, cfg.thresholds)
    if pick is not None:
        return Action("switch", pick.name, resume_message, reason)

    recovery = recover_soonest(candidates, cache, cfg.thresholds, now=now_seconds())
    if recovery is not None and cfg.wait_for_reset:
        return Action("wait", recovery.name, resume_message, reason)
    return Action("none", None, resume_message, reason)


def decide_action(*, pending_signal: str | None, signal_payload: dict,
                   cfg: Config, active_over_threshold: bool,
                   candidates: list[Candidate], current: Candidate | None,
                   cache: dict[str, AccountUsage]) -> Action:
    """Decide the wrapper's next move for the given pending signal.

    Priority: rate-limit and turn-failed are per-turn
    reactions; switch-requested is explicit user intent; threshold switching
    only fires on STOPPED (turn boundary) and never in manual mode (manual
    mode only ever suggests via "none").

    :param pending_signal: the signal name read off the file bus, or None.
    :param signal_payload: the signal's parsed payload dict.
    :param cfg: user behaviour configuration.
    :param active_over_threshold: whether the active account is over its configured threshold.
    :param candidates: all managed accounts.
    :param current: the active account, or None.
    :param cache: usage-cache-key → AccountUsage.
    :return: the Action to take.
    """
    if pending_signal == signals.RATE_LIMITED and cfg.auto_switch_on_rate_limit:
        reason = signal_payload.get("message") or "rate limited"
        return _switch_or_wait(cfg, candidates, current, cache, reason)

    if pending_signal == signals.TURN_FAILED and cfg.retry_on_api_error:
        reason = signal_payload.get("message") or "turn failed"
        return Action("retry_same", None, None, reason)

    if pending_signal == signals.SWITCH_REQUESTED:
        resume_message = signal_payload.get("resume_message")
        target = signal_payload.get("target")
        if target:
            return Action("switch", target, resume_message, "explicit switch requested")
        if cfg.strategy.kind != "manual":
            return _switch_or_wait(cfg, candidates, current, cache, "switch requested",
                                   resume_message=resume_message)
        return Action("none", None, resume_message, "switch requested in manual mode")

    if (pending_signal == signals.STOPPED and cfg.auto_switch_on_threshold
            and active_over_threshold and cfg.strategy.kind != "manual"):
        return _switch_or_wait(cfg, candidates, current, cache, "active account over threshold")

    return Action("none", None, None, "no action")
