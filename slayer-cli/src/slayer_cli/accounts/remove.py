"""Remove an account slot without leaving a dangling active pointer.

`AccountStore.remove()` is intentionally mechanical — it never touches
`state.json`, documenting the gap as "a dangling pointer the caller is
responsible for handling". This is that caller: when the removed slot was
the active one, it switches to the most-recently-used remaining account
(a real switch — credential + attribution written for it), or clears the
active pointer and the stale `account-provider/active.json` (what the hook
actually reads for attribution) when none remain, so a deleted account
never keeps attributing events to itself."""
from __future__ import annotations

from slayer_cli.accounts.store import AccountStore
from slayer_cli.accounts.switch import switch_to
from slayer_cli.platform.paths import Paths

__all__ = ["remove_account"]


def remove_account(store: AccountStore, paths: Paths, name: str) -> None:
    """Remove slot `name`. If `name` was the active slot, switch to the
    most-recently-used remaining account, or clear the active pointer and
    attribution file if none remain.

    :param store: Account slot store.
    :param paths: Resolved OS paths for this namespace.
    :param name: Slot name to remove.
    :return: None
    :raises AccountNotFound: If no slot file exists for `name`.
    """
    was_active = store.active() == name
    store.remove(name)
    if not was_active:
        return
    remaining = store.list()
    if remaining:
        next_account = max(remaining, key=lambda a: a.last_used or 0)
        switch_to(store, next_account.name, paths=paths)
    else:
        store.clear_active()
        paths.active_file.unlink(missing_ok=True)
