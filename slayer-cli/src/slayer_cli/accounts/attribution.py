"""Reconcile the hook's attribution files for the account that just became
active — shared by `switch`, `setup`, and `detect-base` so every path that
changes the active login attributes usage to the right account."""
from __future__ import annotations

import sys

from slayer_cli import credstore
from slayer_cli.models.account import Account
from slayer_cli.platform.paths import Paths
from slayer_cli.provider import writer as provider_writer

__all__ = ["reconcile_active"]


def reconcile_active(paths: Paths, account: Account) -> None:
    """Point both of the hook's attribution sources at `account`.

    Patches `.claude.json`'s `oauthAccount` (the hook's credential-path
    fallback label) and writes `account-provider/active.json` (the hook's
    highest-priority source). When the account has no `org_uuid` the provider
    file cannot be written correctly, so any stale one is removed and a warning
    printed — the hook then degrades to auto/Unrecognized rather than
    misattributing this account's usage to a previous slot's org.

    :param paths: Resolved OS paths for this namespace.
    :param account: The account that just became active.
    :return: None
    """
    credstore.claude_json.patch_oauth_account(paths, account.email, account.uuid, account.org_uuid)
    if account.org_uuid:
        provider_writer.write_active(paths, account)
    else:
        paths.active_file.unlink(missing_ok=True)
        print(
            f"warning: attribution unavailable for '{account.name}' — its "
            "organization could not be resolved; usage will not be attributed "
            "until it is.",
            file=sys.stderr,
        )
