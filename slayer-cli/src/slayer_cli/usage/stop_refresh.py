"""Warm the active account's usage cache on Claude Code's Stop hook.

Independent of auto-switch: unlike `autoswitch.hooks`, this is NOT
`TS_WRAPPED`-gated, so it runs on every plain `claude` session. It only
refreshes the ACTIVE account (one HTTP probe, not the whole pool) and writes
into the same TTL cache `usage.service.UsageService` reads, so the TUI's
ticker/manual-refresh sees a warm snapshot without the user waiting out the
next probe."""
from __future__ import annotations

import sys

from slayer_cli.accounts.store import AccountStore
from slayer_cli.platform.paths import Paths
from slayer_cli.usage.service import UsageService

__all__ = ["refresh_active_on_stop"]


def refresh_active_on_stop(paths: Paths) -> None:
    """Force-probe the active account's usage and cache it, best-effort.

    No-ops silently when there is no active slot, or the active pointer is
    dangling (points at a removed slot). Any other failure (network, disk,
    ...) is swallowed and logged to stderr rather than raised — a hook must
    never break the user's Claude Code turn.

    :param paths: Resolved OS paths for this namespace.
    :return: None
    """
    try:
        store = AccountStore(paths)
        active_name = store.active()
        if not active_name or not store.exists(active_name):
            return
        account = store.get(active_name)
        UsageService(paths).get(account, force=True)
    except Exception as exc:  # noqa: BLE001 - hook boundary: must never crash the turn
        print(f"token-slayer: usage refresh failed: {exc}", file=sys.stderr)
