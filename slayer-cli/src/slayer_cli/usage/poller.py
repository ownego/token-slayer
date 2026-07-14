"""Refresh + poll usage for every managed account, for the auto-switch loop.

Getting fresh usage for ALL accounts (not just the active one) is what lets
the strategy pick a genuinely-available switch target. The tokens have
different owners, so the refresh policy differs:

- The ACTIVE account's grant is owned by Claude Code, which self-rotates its
  ~8h access token. We read that live grant and never refresh it here —
  refreshing would clobber Claude's single-use refresh token. The active
  account is always polled (it is the account being gated on).
- A NON-active account is owned solely by the wrapper. Its stored access
  token drifts stale (Claude is not keeping it fresh), so we refresh it via
  the stored refresh token (~29d), persist the rotated grant back to the
  slot, and poll with the fresh token. This is safe from clobber: nothing
  else holds that grant. A per-account TTL (`USAGE_TTL_SECONDS`) skips
  non-active accounts whose cached usage is still fresh, so a busy turn
  boundary does not re-poll every account every time.
"""
from __future__ import annotations

from slayer_cli import credstore
from slayer_cli.accounts.store import AccountStore
from slayer_cli.constants import USAGE_TTL_SECONDS
from slayer_cli.credstore import refresh as credstore_refresh
from slayer_cli.errors import SlayerError
from slayer_cli.models.account import Account
from slayer_cli.models.usage_windows import AccountUsage
from slayer_cli.platform.paths import Paths
from slayer_cli.usage import oauth as usage_oauth
from slayer_cli.usage.cache import cache_key, load_cache, save_cache

__all__ = ["refresh_all_usage"]


def _token_for_non_active(store: AccountStore, account: Account) -> str:
    """Return a fresh access token for a non-active account, refreshing its
    stored grant when the access token is expired.

    Safe from clobber: only the wrapper owns a non-active account's grant, so
    rotating it (and persisting the rotated grant back to the slot) cannot
    invalidate Claude Code's live credential. A failed refresh (the refresh
    token is also dead, e.g. older than ~29d) falls back to the stale token —
    the subsequent usage fetch just 401s into an empty snapshot rather than
    crashing the poll loop.

    :param store: The account store (the rotated grant is written back here).
    :param account: The non-active account slot.
    :return: A best-effort fresh access token to poll `account` with.
    """
    if not account.refresh_token:
        return account.token
    block = {
        "accessToken": account.token,
        "refreshToken": account.refresh_token,
        "expiresAt": account.expires_at,
    }
    if not credstore_refresh.is_expired(block):
        return account.token
    try:
        refreshed = credstore_refresh.refresh_grant(block)
    except SlayerError:
        return account.token
    store.add(account.model_copy(update={
        "token": refreshed["accessToken"],
        "refresh_token": refreshed.get("refreshToken") or account.refresh_token,
        "expires_at": refreshed.get("expiresAt") or account.expires_at,
    }))
    return refreshed["accessToken"]


def refresh_all_usage(store: AccountStore, paths: Paths, now: int) -> dict[str, AccountUsage]:
    """Return the usage cache with a fresh entry for every managed account.

    The active account is polled with the live grant Claude maintains (always,
    regardless of TTL — it is the account being gated). Non-active accounts are
    refreshed (safely) then polled, but only when their cached usage is missing
    or older than `USAGE_TTL_SECONDS`. The updated cache is persisted and
    returned.

    :param store: The account store.
    :param paths: Resolved OS paths for this namespace.
    :param now: Current unix time in seconds (`now_seconds()`).
    :return: The updated usage cache (also written to disk).
    """
    cache = load_cache(paths)
    active_name = store.active()
    live = credstore.read_active_full(paths)
    for account in store.list():
        key = cache_key(account)
        is_active = account.name == active_name
        cached = cache.get(key)
        if not is_active and cached is not None and cached.polled_at and (now - cached.polled_at) < USAGE_TTL_SECONDS:
            continue
        if is_active:
            token = (live or {}).get("accessToken") or account.token
        else:
            token = _token_for_non_active(store, account)
        cache[key] = usage_oauth.fetch_usage(token)
    save_cache(paths, cache)
    return cache
