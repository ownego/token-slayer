"""Decide which account to switch to. Pure: operates on Candidates + a usage
cache, so it is trivially unit-testable."""
from __future__ import annotations
from dataclasses import dataclass
from slayer_cli.models.usage_windows import AccountUsage, Thresholds, Window, is_over_threshold


@dataclass(frozen=True)
class Candidate:
    """An account eligible for selection.

    :var name: account name.
    :var key: usage-cache key; MUST equal usage.cache.cache_key(account) for the cache lookup to hit.
    """
    name: str
    key: str


@dataclass(frozen=True)
class Pick:
    """The result of a selection: the chosen account and why it was chosen.

    :var name: the chosen account's name.
    :var reason: human-readable reason for the pick.
    """
    name: str
    reason: str


def _u(cache: dict[str, AccountUsage], key: str) -> AccountUsage | None:
    """Return the cached usage for `key`, or None if absent."""
    return cache.get(key)


def _util(w: Window | None) -> float:
    """Return a window's utilization, or 0.0 if the window is missing."""
    return w.utilization if w is not None else 0.0


def _five(cache: dict[str, AccountUsage], key: str) -> float:
    """Return the account's 5h utilization, or 0.0 if unknown."""
    u = _u(cache, key)
    return _util(u.five_hour) if u else 0.0


def _seven(cache: dict[str, AccountUsage], key: str) -> float:
    """Return the account's 7d utilization, or 0.0 if unknown."""
    u = _u(cache, key)
    return _util(u.seven_day) if u else 0.0


def _available(cache: dict[str, AccountUsage], key: str) -> bool:
    """Return True unless the account's cached usage marks its token expired."""
    u = _u(cache, key)
    return True if u is None else not u.token_expired


def _model_capped(cache: dict[str, AccountUsage], key: str) -> bool:
    """Return True if the account's 7d opus or sonnet window is at the hard limit."""
    u = _u(cache, key)
    if u is None:
        return False
    return any(w is not None and w.utilization >= 100 for w in (u.seven_day_opus, u.seven_day_sonnet))


def _over(cache: dict[str, AccountUsage], key: str, thresholds: Thresholds) -> bool:
    """Return True if the account is over its configured threshold."""
    u = _u(cache, key)
    return bool(u and is_over_threshold(u, thresholds)[0])


def _caps(thresholds: Thresholds) -> tuple[float, float]:
    """Return the (5h, 7d) drain-mode caps, falling back to defaults when unconfigured."""
    cap7 = thresholds.seven_day if 0 < thresholds.seven_day < 100 else 95
    cap5 = thresholds.five_hour if 0 < thresholds.five_hour < 100 else 90
    return cap5, cap7


def pick_next(kind: str, order: list[str], candidates: list["Candidate"], current: "Candidate | None", cache: dict[str, "AccountUsage"], thresholds: "Thresholds") -> "Pick | None":
    """Choose the account to swap to. Returns None in manual mode, when nothing
    has capacity, or when there are no candidates.

    :param kind: "manual" | "balanced" | "drain".
    :param order: drain priority (names); empty = auto by highest 7d.
    :param candidates: all managed accounts.
    :param current: the active account (excluded), or None.
    :param cache: usage-cache-key → AccountUsage.
    :param thresholds: configured thresholds.
    :return: a Pick, or None.
    """
    if kind == "manual":
        return None
    others = [c for c in candidates if current is None or c.name != current.name]
    if kind == "balanced":
        return _pick_balanced(others, cache, thresholds)
    return _pick_drain(order, others, cache, thresholds)


def _eligible(c: Candidate, cache: dict[str, AccountUsage], thresholds: Thresholds) -> bool:
    """Return True if `c` is available, under threshold, and not 7d-capped."""
    return (_available(cache, c.key) and not _over(cache, c.key, thresholds)
            and _seven(cache, c.key) < 100)


def _pick_balanced(others: list[Candidate], cache: dict[str, AccountUsage], thresholds: Thresholds) -> Pick | None:
    """Return the eligible candidate with the lowest 7d utilization, or None if none are eligible."""
    pool = [c for c in others if _eligible(c, cache, thresholds)]
    if not pool:
        return None
    pool.sort(key=lambda c: (_model_capped(cache, c.key), _seven(cache, c.key), _five(cache, c.key)))
    return Pick(pool[0].name, "balanced: lowest 7d")


def _pick_drain(order: list[str], others: list[Candidate], cache: dict[str, AccountUsage], thresholds: Thresholds) -> Pick | None:
    """Return the first drain-eligible candidate under the drain caps, or None if none have room."""
    cap5, cap7 = _caps(thresholds)
    if order:
        ordered = [c for name in order for c in others if c.name == name]
    else:
        ordered = sorted(others, key=lambda c: _seven(cache, c.key), reverse=True)
    for prefer_model_clear in (True, False):
        for c in ordered:
            if prefer_model_clear and _model_capped(cache, c.key):
                continue
            if _available(cache, c.key) and _five(cache, c.key) < cap5 and _seven(cache, c.key) < cap7:
                return Pick(c.name, "drain: 7d under cap")
    for prefer_model_clear in (True, False):
        for c in ordered:
            if prefer_model_clear and _model_capped(cache, c.key):
                continue
            if _available(cache, c.key) and _five(cache, c.key) < cap5 and _seven(cache, c.key) < 100:
                return Pick(c.name, "drain: 5h has room")
    return None
