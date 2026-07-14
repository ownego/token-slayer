"""Pull admin-provisioned grants from the token-slayer server and set them up.

The server's `GET /api/provisioned` returns `expires_at` as a UNIX
**seconds** timestamp; Claude Code's `.credentials.json` wants
`expiresAt` in **milliseconds**, so the seconds value is multiplied by
1000 before being handed to `credstore.write_active_full`.
"""
from __future__ import annotations

import json
import os
import time

import httpx

from slayer_cli import credstore
from slayer_cli.accounts import attribution
from slayer_cli.accounts.store import AccountStore
from slayer_cli.errors import ProvisioningError
from slayer_cli.models.account import Account
from slayer_cli.platform.http import client as make_client
from slayer_cli.platform.http import request_headers
from slayer_cli.platform.paths import Paths

__all__ = ["pull_and_setup"]


def _base_url() -> str:
    """Return the token-slayer server's base URL.

    Real installs never set `SLAYER_API_BASE` (the install script doesn't
    export it), so this default IS what every production `setup` call
    hits. Only dev/staging work should ever override it.

    :return: `SLAYER_API_BASE` if set, otherwise production.
    """
    return os.environ.get("SLAYER_API_BASE") or "https://token-slayer.ownego.com"


def _status_error(exc: httpx.HTTPStatusError) -> ProvisioningError:
    """Map an HTTP error response to a helpful `ProvisioningError`.

    :param exc: The raised `httpx.HTTPStatusError`.
    :return: A `ProvisioningError` with a user-facing message.
    """
    status = exc.response.status_code
    if status in (401, 403):
        return ProvisioningError(
            "server rejected the hook token (HTTP {0}). Your token may be for "
            "a different token-slayer install — check with an admin, or "
            "re-run the install command from your profile page.".format(status)
        )
    return ProvisioningError(f"server returned HTTP {status} for /api/provisioned")


def pull_and_setup(paths: Paths, hook_token: str, *, client: httpx.Client | None = None) -> list[str]:
    """Fetch the user's admin-provisioned grants and configure Claude Code.

    Calls `GET <base>/api/provisioned` (Bearer `hook_token`) and, for each
    returned account, upserts an `AccountStore` slot. The FIRST account
    returned is also installed as Claude Code's active credential via
    `credstore.write_active_full` (real refresh token, so Claude Code
    self-refreshes) and marked active in the store. Switching away later
    will re-write a null refresh token for provisioned accounts (v1
    limitation — see `.ai/domain/switching.md`).

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
    except httpx.HTTPStatusError as exc:
        raise _status_error(exc) from exc
    except httpx.HTTPError as exc:
        raise ProvisioningError("could not reach the token-slayer server") from exc
    except ValueError as exc:
        raise ProvisioningError("server returned an invalid (non-JSON) response") from exc
    finally:
        if own_client:
            http_client.close()

    store = AccountStore(paths)
    names: list[str] = []
    for index, account_payload in enumerate(accounts):
        # Server sends expires_at in UNIX seconds; slots (like Claude Code's
        # credential block) hold it in milliseconds.
        expires_at_ms = int(account_payload["expires_at"]) * 1000
        account = Account(
            name=account_payload["name"],
            email=account_payload.get("email"),
            org_uuid=account_payload.get("org_uuid"),
            uuid=None,
            plan=None,
            token=account_payload["access_token"],
            refresh_token=account_payload.get("refresh_token"),
            expires_at=expires_at_ms,
            added_at=int(time.time()),
            last_used=None,
        )
        store.add(account)
        if index == 0:
            credstore.write_active_full(
                paths,
                account_payload["access_token"],
                account_payload["refresh_token"],
                expires_at_ms,
            )
            # Read back the live grant we just wrote. A real incident: the
            # server recorded a successful claim while the client's write
            # silently never took effect (disk/permission quirk, a race with
            # something else touching the credential file), leaving the
            # user's live Claude session on their OLD account while `setup`
            # still printed success — usage kept getting attributed to
            # whichever account was active before. Catch that here instead
            # of reporting success on an account that never actually went
            # live.
            live = credstore.read_active_full(paths)
            if not live or live.get("accessToken") != account_payload["access_token"]:
                raise ProvisioningError(
                    "the account was provisioned but writing it as your active Claude "
                    "credential did not take effect — check that Claude Code isn't "
                    "running under a different CLAUDE_CONFIG_DIR, then retry `token-slayer setup`."
                )
            store.set_active(account.name)
            # Point the hook's attribution at this now-active account so its
            # usage is attributed correctly from the first event (not only
            # after a later `switch`).
            attribution.reconcile_active(paths, account)
            # Read back what the hook will actually see. This is a SEPARATE
            # write from the credential above (a different file,
            # account-provider/active.json) and can silently fail on its own
            # — the TUI only reads state.json (already correct by this
            # point), so it would show the new account selected even while
            # every event kept attributing to the previous one. Only checked
            # when org_uuid is present: a blank org_uuid is the documented
            # unattributable case (reconcile_active removes active.json and
            # warns instead of writing it).
            if account.org_uuid and paths.active_file.is_file():
                live_provider = json.loads(paths.active_file.read_text())
            else:
                live_provider = None
            if account.org_uuid and (live_provider or {}).get("org_uuid") != account.org_uuid:
                raise ProvisioningError(
                    "the account was provisioned and is now your active Claude credential, "
                    "but attribution (account-provider/active.json) did not update — usage "
                    "may still attribute to the previous account. Retry `token-slayer setup`."
                )
        names.append(account_payload["name"])
    return names
