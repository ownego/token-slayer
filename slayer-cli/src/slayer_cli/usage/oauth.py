"""Fetch structured usage from GET /api/oauth/usage (metadata read, no
message-quota cost). Requires a full Pro/Max OAuth token; setup-tokens 403.
"""
from __future__ import annotations
import time
import httpx
from slayer_cli.models.usage_windows import AccountUsage, Window
from slayer_cli.platform.http import client as make_client

_ENDPOINT = "https://api.anthropic.com/api/oauth/usage"
_BETA = "oauth-2025-04-20"


def _window(raw: dict | None) -> Window | None:
    """Parse a window from raw API response, or None if missing/invalid."""
    if not isinstance(raw, dict) or "utilization" not in raw:
        return None
    return Window(utilization=float(raw["utilization"]), resets_at=raw.get("resets_at"))


def fetch_usage(token: str, *, client: httpx.Client | None = None) -> AccountUsage:
    """Fetch per-window usage for `token`. 401 → token_expired; any other
    failure → an empty (all-None) snapshot, so one bad account never poisons a
    caller iterating over many.

    :param token: A Pro/Max OAuth access token.
    :param client: Optional injected httpx client (tests).
    :return: Parsed `AccountUsage`.
    """
    own = client is None
    c = client or make_client()
    headers = {
        "Authorization": f"Bearer {token}",
        "anthropic-beta": _BETA,
        "Accept": "application/json",
    }
    try:
        r = c.get(_ENDPOINT, headers=headers)
    except httpx.HTTPError:
        return AccountUsage(polled_at=int(time.time()))
    finally:
        if own:
            c.close()
    if r.status_code == 401:
        return AccountUsage(polled_at=int(time.time()), token_expired=True)
    if r.status_code != 200:
        return AccountUsage(polled_at=int(time.time()))
    try:
        d = r.json()
    except ValueError:
        return AccountUsage(polled_at=int(time.time()))
    return AccountUsage(
        five_hour=_window(d.get("five_hour")),
        seven_day=_window(d.get("seven_day")),
        seven_day_opus=_window(d.get("seven_day_opus")),
        seven_day_sonnet=_window(d.get("seven_day_sonnet")),
        polled_at=int(time.time()),
    )
