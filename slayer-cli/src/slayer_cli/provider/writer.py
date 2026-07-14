"""Writes account-provider/active.json — the attribution contract the hook reads."""
from __future__ import annotations
import os
from pathlib import Path
from slayer_cli.models.account import Account
from slayer_cli.models.provider import ActiveJson
from slayer_cli.platform.paths import Paths


def write_active(paths: Paths, account: Account) -> None:
    """Build and atomically write active.json with account attribution.

    Builds ActiveJson with the account's org_uuid and email, source="switcher".
    Raises ValidationError (from pydantic) if org_uuid is blank — the caller
    (the switch service) is responsible for ensuring a beacon ran first.

    Creates the provider_dir (0700) if absent, then writes via .tmp+replace,
    leaving active.json (0600) for consistency with other credential files.

    :param paths: Resolved filesystem paths for this namespace.
    :param account: The account to write attribution for.
    :return: None
    :raises ValidationError: If account.org_uuid is blank.
    """
    active = ActiveJson(org_uuid=account.org_uuid, email=account.email, source="switcher")
    _harden_dir(paths.provider_dir)
    _atomic_write(paths.active_file, active.model_dump_json())


def _harden_dir(path: Path) -> None:
    """Create path (and parents) if absent and force it to 0700.

    :param path: Directory to create and harden.
    :return: None
    """
    path.mkdir(parents=True, exist_ok=True)
    os.chmod(path, 0o700)


def _atomic_write(path: Path, text: str) -> None:
    """Write text to path atomically, leaving the file 0600.

    Writes to a sibling .tmp file created 0600 from the start, then
    replace()s it onto path — a crash/kill mid-write leaves the live
    file untouched instead of truncated/corrupt JSON.

    :param path: Final destination path.
    :param text: File contents to write.
    :return: None
    """
    tmp = path.with_suffix(".tmp")
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as handle:
        handle.write(text)
    tmp.replace(path)
