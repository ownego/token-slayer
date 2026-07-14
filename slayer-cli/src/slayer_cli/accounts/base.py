"""Register the machine's currently active Claude login as a base account slot.

Run once at install time so a user who already uses Claude Code sees their
existing account in token-slayer immediately, without a manual `add`. Safe to
call on every (re)install: it is idempotent and identity-deduplicated.
"""
from __future__ import annotations

from slayer_cli.accounts import attribution
from slayer_cli.accounts.detect import detect_current
from slayer_cli.accounts.store import AccountStore
from slayer_cli.models.account import Account
from slayer_cli.platform.paths import Paths
from slayer_cli.usage.cache import cache_key

__all__ = ["add_base_account"]


def _same_account(a: Account, b: Account) -> bool:
    """Whether two account records describe the same underlying account.

    More lenient than `cache_key` equality on purpose: a provisioned slot
    (uuid unset → org-only key) and the same account detected from the live
    login (uuid present → uuid|org key) have DIFFERENT cache keys but are the
    same account. Matching on uuid or email as well prevents a re-install from
    overwriting (and clobbering the refresh token of) an existing slot.

    :param a: One account record.
    :param b: The other account record.
    :return: True if they are the same account.
    """
    if a.uuid and b.uuid and a.uuid == b.uuid:
        return True
    if a.email and b.email and a.email.lower() == b.email.lower():
        return True
    return cache_key(a) == cache_key(b)


def add_base_account(store: AccountStore, paths: Paths) -> tuple[Account | None, str]:
    """Snapshot the machine's active Claude login into a base slot, unless an
    equivalent slot already exists.

    Cases (all safe to hit on every install):
    - No active Claude login → ``(None, "none")``; nothing written.
    - A slot already carries the same identity (`cache_key` — uuid|org, then
      org, then email) → ``(that slot, "exists")``; nothing written, no
      duplicate even if the existing slot has a different name.
    - Otherwise the detected login is stored (named by its full email, matching
      the provisioned-slot convention so a later provisioned pull of the same
      account overwrites this slot instead of duplicating it) and marked active
      only when no slot is active yet → ``(account, "added")``.

    :param store: Account slot store.
    :param paths: Resolved OS paths for this namespace.
    :return: ``(account or None, status)`` where status is
        ``"added"`` | ``"exists"`` | ``"none"``.
    """
    detected = detect_current(paths)
    if detected is None:
        return None, "none"

    for existing in store.list():
        if _same_account(existing, detected):
            return existing, "exists"

    name = detected.email or detected.name
    account = detected if name == detected.name else detected.model_copy(update={"name": name})
    store.add(account)
    if store.active() is None:
        store.set_active(account.name)
        # This IS the machine's live login, so point the hook's attribution at
        # it too — its usage is attributed correctly without a later `switch`.
        attribution.reconcile_active(paths, account)
    return account, "added"
