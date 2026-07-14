"""When no account is usable now, pick the one that recovers soonest — the
reset-soon rule. Prefer an account blocked ONLY by its 5h window (its scarce 7d
is intact and 5h resets within hours). Pure."""
from __future__ import annotations
from dataclasses import dataclass
from slayer_cli.strategy.select import Candidate, Pick
from slayer_cli.models.usage_windows import AccountUsage, Thresholds, is_over_threshold


@dataclass(frozen=True)
class Recovery:
    """The soonest-recovering account and how it is blocked.

    :var name: the account's name.
    :var available_at: unix seconds when the account becomes usable.
    :var only_five_hour: True if ONLY the 5h window blocks it (7d is healthy).
    """
    name: str
    available_at: int
    only_five_hour: bool


def _binding_reset(u: AccountUsage, thresholds: Thresholds) -> tuple[int | None, bool]:
    """Return (available_at, only_five_hour): when this account recovers and why."""
    five_over = u.five_hour is not None and (u.five_hour.utilization >= 100
        or (0 < thresholds.five_hour < 100 and u.five_hour.utilization >= thresholds.five_hour))
    seven_over = u.seven_day is not None and (u.seven_day.utilization >= 100
        or (0 < thresholds.seven_day < 100 and u.seven_day.utilization >= thresholds.seven_day))
    resets: list[int | None] = []
    if five_over:
        resets.append(u.five_hour.resets_at if u.five_hour else None)
    if seven_over:
        resets.append(u.seven_day.resets_at if u.seven_day else None)
    if not resets or any(r is None for r in resets):
        return None, False
    return max(resets), (five_over and not seven_over)


def recover_soonest(candidates: list[Candidate], cache: dict[str, AccountUsage], thresholds: Thresholds, *, now: int) -> Recovery | None:
    """Return the account that becomes usable soonest, preferring a 5h-only
    block over a 7d block. None if nothing has recoverable reset info.

    :param candidates: all managed accounts.
    :param cache: usage-cache-key → AccountUsage.
    :param thresholds: configured thresholds.
    :param now: current unix seconds (injected for tests).
    :return: the soonest-recovering account, or None.
    """
    best: Recovery | None = None
    for c in candidates:
        u = cache.get(c.key)
        if u is None:
            continue
        avail, only5 = _binding_reset(u, thresholds)
        if avail is None or avail <= now:
            continue
        cand = Recovery(c.name, avail, only5)
        if best is None:
            best = cand
        else:
            better = (not cand.only_five_hour, cand.available_at) < (not best.only_five_hour, best.available_at)
            if better:
                best = cand
    return best


def should_rebalance(order: list[str], candidates: list[Candidate], current: Candidate | None, cache: dict[str, AccountUsage], thresholds: Thresholds) -> Pick | None:
    """Drain-mode only: the first healthy priority account to hop back to when
    the user is on a temp account. None when no rebalance is warranted.

    :param order: drain priority (names), highest priority first.
    :param candidates: all managed accounts.
    :param current: the active account, or None.
    :param cache: usage-cache-key → AccountUsage.
    :param thresholds: configured thresholds.
    :return: a Pick for the priority account to hop back to, or None.
    """
    if not order or current is None:
        return None
    for name in order:
        c = next((x for x in candidates if x.name == name), None)
        if c is None or c.name == current.name:
            continue
        u = cache.get(c.key)
        if u is not None and (u.token_expired or is_over_threshold(u, thresholds)[0]):
            continue
        if u is not None and any(w is not None and w.utilization >= 100
                                 for w in (u.seven_day_opus, u.seven_day_sonnet)):
            continue
        return Pick(c.name, "rebalance to priority account")
    return None
