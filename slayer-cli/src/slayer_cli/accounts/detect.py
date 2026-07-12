"""Detect the currently active Claude Code login, for the `add` snapshot
flow and for status reporting. See `.ai/domain/account-slots.md`."""
from __future__ import annotations

import time

from slayer_cli import credstore
from slayer_cli.auth import beacon
from slayer_cli.models.account import Account
from slayer_cli.platform.paths import Paths

__all__ = ["detect_current"]


def detect_current(paths: Paths) -> Account | None:
    """Build an `Account` describing whatever Claude Code login is
    currently active on this machine, without persisting it.

    Reads the active credential, beacons its organization uuid, and fills
    email/uuid from `.claude.json`'s `oauthAccount` block (best-effort —
    an empty/missing block just leaves those fields unset).

    :param paths: Resolved OS paths for this namespace.
    :return: The detected `Account`, or None if no credential is active.
    """
    token = credstore.read_active_token(paths)
    if token is None:
        return None
    org_uuid = beacon.resolve_org_uuid(token)
    oauth_account = credstore.claude_json.read_oauth_account(paths)
    email = oauth_account.get("emailAddress")
    uuid = oauth_account.get("accountUuid")
    name = email.split("@")[0] if email else "default"
    return Account(
        name=name,
        email=email,
        uuid=uuid,
        org_uuid=org_uuid,
        plan=None,
        token=token,
        added_at=int(time.time()),
        last_used=None,
    )
