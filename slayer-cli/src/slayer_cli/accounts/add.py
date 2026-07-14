"""Add a new account slot: either snapshot the currently active Claude Code
login, or drive a PKCE `--login` flow. See `.ai/domain/account-slots.md`.

Collaborators (`credstore`, `beacon`, `pkce`) are imported as modules, not
as `from ... import name`, so tests can `monkeypatch.setattr` them on this
module's namespace."""
from __future__ import annotations

import time
from typing import Callable

from slayer_cli import credstore
from slayer_cli.accounts.store import AccountStore
from slayer_cli.auth import beacon
from slayer_cli.auth import pkce
from slayer_cli.errors import CredentialError
from slayer_cli.models.account import Account
from slayer_cli.platform.paths import Paths

__all__ = ["add_snapshot", "add_via_login"]


def add_snapshot(store: AccountStore, paths: Paths, name: str) -> Account:
    """Snapshot the currently active Claude Code login into a new slot.

    :param store: Account slot store to write into.
    :param paths: Resolved OS paths for this namespace.
    :param name: Slot name to create.
    :return: The stored `Account`.
    :raises CredentialError: If no Claude Code credential is currently active.
    """
    token = credstore.read_active_token(paths)
    if token is None:
        raise CredentialError("no active Claude Code credential to snapshot")
    org_uuid = beacon.resolve_org_uuid(token)
    oauth_account = credstore.claude_json.read_oauth_account(paths)
    account = Account(
        name=name,
        email=oauth_account.get("emailAddress"),
        uuid=oauth_account.get("accountUuid"),
        org_uuid=org_uuid,
        plan=None,
        token=token,
        added_at=int(time.time()),
    )
    store.add(account)
    return account


def add_via_login(
    store: AccountStore,
    paths: Paths,
    name: str,
    code_provider: Callable[[str], str],
) -> Account:
    """Add a new slot by driving a fresh PKCE login rather than snapshotting
    the machine's current credential.

    Deliberately does NOT read `.claude.json` — right after a fresh login
    it still reflects the PREVIOUS active account, so its email/uuid would
    be wrong here. Only `org_uuid` (from the beacon) is attribution-critical;
    email/uuid fill in later via a `detect`/refresh against the new token.

    :param store: Account slot store to write into.
    :param paths: Resolved OS paths for this namespace (unused directly —
        threaded through for interface symmetry with `add_snapshot`).
    :param name: Slot name to create.
    :param code_provider: Called with the authorize URL; must return the
        `code#state` value the user pasted after authenticating in their
        own browser. The CLI passes a real prompt; tests pass a stub. This
        module never logs in or automates the browser itself.
    :return: The stored `Account`.
    :raises LoginError: If the PKCE code exchange fails.
    """
    authorize_url, verifier, _state = pkce.start()
    raw_code = code_provider(authorize_url)
    token = pkce.exchange(raw_code, verifier)
    org_uuid = beacon.resolve_org_uuid(token)
    account = Account(
        name=name,
        email=None,
        uuid=None,
        org_uuid=org_uuid,
        plan=None,
        token=token,
        added_at=int(time.time()),
    )
    store.add(account)
    return account
