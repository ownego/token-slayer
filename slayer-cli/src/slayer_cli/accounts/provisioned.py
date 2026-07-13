"""Pull admin-provisioned grants from the token-slayer server and set them up.

The server's `GET /api/provisioned` returns `expires_at` as a UNIX
**seconds** timestamp; Claude Code's `.credentials.json` wants
`expiresAt` in **milliseconds**, so the seconds value is multiplied by
1000 before being handed to `credstore.write_active_full`.
"""
from __future__ import annotations

import os
import time

import httpx

from slayer_cli import credstore
from slayer_cli.accounts.store import AccountStore
from slayer_cli.models.account import Account
from slayer_cli.platform.http import client as make_client
from slayer_cli.platform.http import request_headers
from slayer_cli.platform.paths import Paths

__all__ = ["pull_and_setup"]


def _base_url() -> str:
    """Return the token-slayer server's base URL.

    :return: `SLAYER_API_BASE` if set, otherwise the default install origin.
    """
    return os.environ.get("SLAYER_API_BASE") or "https://ts.tungot.dev"


def pull_and_setup(paths: Paths, hook_token: str, *, client: httpx.Client | None = None) -> list[str]:
    """Fetch the user's admin-provisioned grants and configure Claude Code.

    Calls `GET <base>/api/provisioned` (Bearer `hook_token`) and, for each
    returned account, upserts an `AccountStore` slot. The FIRST account
    returned is also installed as Claude Code's active credential via
    `credstore.write_active_full` (real refresh token, so Claude Code
    self-refreshes) and marked active in the store. Switching away later
    will re-write a null refresh token for provisioned accounts (v1
    limitation — see `.ai/domain/accounts.md`).

    :param paths: Resolved OS paths for the active namespace.
    :param hook_token: The user's hook token (Bearer auth to the server).
    :param client: Optional injected `httpx.Client` (tests); a real one is
        created and closed internally when omitted.
    :return: The names of the accounts set up, in server response order.
    """
    own_client = client is None
    http_client = client or make_client()
    try:
        response = http_client.get(_base_url() + "/api/provisioned", headers=request_headers(hook_token))
        response.raise_for_status()
        accounts = response.json().get("accounts", [])
    finally:
        if own_client:
            http_client.close()

    store = AccountStore(paths)
    names: list[str] = []
    for index, account_payload in enumerate(accounts):
        account = Account(
            name=account_payload["name"],
            email=account_payload.get("email"),
            org_uuid=account_payload.get("org_uuid"),
            uuid=None,
            plan=None,
            token=account_payload["access_token"],
            added_at=int(time.time()),
            last_used=None,
        )
        store.add(account)
        if index == 0:
            credstore.write_active_full(
                paths,
                account_payload["access_token"],
                account_payload["refresh_token"],
                int(account_payload["expires_at"]) * 1000,
            )
            store.set_active(account.name)
        names.append(account_payload["name"])
    return names
