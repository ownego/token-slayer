"""Linux/Windows active-credential storage: merges into Claude Code's own
`.credentials.json`, mirroring ccm's `_credentials_apply` jq-merge shape."""
from __future__ import annotations
import json
import os
import time
from pathlib import Path
from slayer_cli.errors import CredentialError

SCOPES = [
    "user:file_upload",
    "user:inference",
    "user:mcp_servers",
    "user:profile",
    "user:sessions:claude_code",
]
"""OAuth scopes written into `.claudeAiOauth.scopes` on every switch."""

ONE_YEAR_MS = 31536000000
"""Milliseconds in a year, used to synthesize `.claudeAiOauth.expiresAt`."""


BACKUP_SUFFIX = ".slayer-bak"
"""Filename suffix appended to `creds_file` for the no-clobber pristine backup."""


def backup_path(creds_file: Path) -> Path:
    """Return the no-clobber backup path for `creds_file`.

    :param creds_file: Path to Claude Code's `.credentials.json`.
    :return: `creds_file` with `.slayer-bak` appended to its name.
    """
    return creds_file.with_name(creds_file.name + BACKUP_SUFFIX)


def _atomic_write_bytes(dst: Path, data: bytes) -> None:
    """Write `data` to `dst` atomically (temp file + replace), created 0600.

    The temp file is opened 0600 from the start (not chmod'd after writing),
    so the contents are never briefly group/world-readable under the default
    umask.

    :param dst: Destination path.
    :param data: Raw bytes to write.
    :return: None
    """
    tmp = dst.with_name(dst.name + ".tmp")
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "wb") as handle:
        handle.write(data)
    tmp.replace(dst)


def _backup_if_absent(creds_file: Path) -> None:
    """Preserve the pristine pre-slayer credential file, first overwrite only.

    No-clobber: no-ops if `creds_file` doesn't exist yet (nothing to preserve)
    or a backup already exists (a later switch must never stomp the original
    pre-slayer login held in the backup).

    :param creds_file: Path to Claude Code's `.credentials.json`.
    :return: None
    """
    if not creds_file.is_file():
        return
    backup = backup_path(creds_file)
    if backup.exists():
        return
    _atomic_write_bytes(backup, creds_file.read_bytes())


def write(creds_file: Path, token: str) -> None:
    """Merge `token` into `creds_file`'s `.claudeAiOauth`, preserving other keys.

    Before the first overwrite of an existing `creds_file`, preserves its
    pristine pre-slayer contents to `backup_path(creds_file)` (0600,
    no-clobber — see `_backup_if_absent`), so a switch is reversible via
    `restore_backup`.

    Writes atomically (temp file + replace) and leaves the file at 0600.

    :param creds_file: Path to Claude Code's `.credentials.json`.
    :param token: Raw `sk-ant-oat01-…` access token to install as active.
    :return: None
    """
    data: dict = {}
    if creds_file.is_file():
        try:
            data = json.loads(creds_file.read_text())
        except ValueError:
            data = {}
    data.setdefault("claudeAiOauth", {})
    data["claudeAiOauth"].update(
        {
            "accessToken": token,
            "refreshToken": None,
            "expiresAt": int(time.time() * 1000) + ONE_YEAR_MS,
            "scopes": SCOPES,
        }
    )
    try:
        creds_file.parent.mkdir(parents=True, exist_ok=True)
        os.chmod(creds_file.parent, 0o700)
        _backup_if_absent(creds_file)
        _atomic_write_bytes(creds_file, json.dumps(data, indent=2).encode())
    except OSError as exc:
        raise CredentialError(f"failed to write credential file: {creds_file}") from exc


def restore_backup(creds_file: Path) -> bool:
    """Restore the pristine pre-slayer credential from its `.slayer-bak`, if any.

    Atomically moves the backup back onto `creds_file`, preserving 0600. The
    backup is consumed by a successful restore (a later `write` will create a
    fresh one from whatever is restored).

    :param creds_file: Path to Claude Code's `.credentials.json`.
    :return: True if a backup existed and was restored, False otherwise.
    """
    backup = backup_path(creds_file)
    if not backup.is_file():
        return False
    try:
        creds_file.parent.mkdir(parents=True, exist_ok=True)
        os.chmod(creds_file.parent, 0o700)
        os.chmod(backup, 0o600)
        backup.replace(creds_file)
    except OSError as exc:
        raise CredentialError(f"failed to restore credential backup: {creds_file}") from exc
    return True


def write_full(creds_file: Path, access_token: str, refresh_token: str, expires_at: int) -> None:
    """Write a FULL grant (real refresh token + expiry) so Claude Code self-refreshes.

    Unlike :func:`write` (which nulls the refresh token, ccm-style), this
    persists the real ``refreshToken`` and the real ``expiresAt`` for a
    provisioned grant, preserving other credential keys. Atomic, 0600.

    :param creds_file: Path to Claude Code's `.credentials.json`.
    :param access_token: Raw `sk-ant-oat01-…` access token.
    :param refresh_token: Real `ort01-…` refresh token for Claude Code self-refresh.
    :param expires_at: Token expiry time in milliseconds (Unix epoch * 1000).
    :return: None
    """
    data: dict = {}
    if creds_file.is_file():
        try:
            data = json.loads(creds_file.read_text())
        except ValueError:
            data = {}
    data.setdefault("claudeAiOauth", {})
    data["claudeAiOauth"].update(
        {
            "accessToken": access_token,
            "refreshToken": refresh_token,
            "expiresAt": expires_at,
            "scopes": SCOPES,
        }
    )
    try:
        creds_file.parent.mkdir(parents=True, exist_ok=True)
        os.chmod(creds_file.parent, 0o700)
        _backup_if_absent(creds_file)
        _atomic_write_bytes(creds_file, json.dumps(data, indent=2).encode())
    except OSError as exc:
        raise CredentialError(f"failed to write credential file: {creds_file}") from exc


def read(creds_file: Path) -> str | None:
    """Return the active access token from `creds_file`, or None if absent/unreadable.

    :param creds_file: Path to Claude Code's `.credentials.json`.
    :return: The `.claudeAiOauth.accessToken` value, or None.
    """
    if not creds_file.is_file():
        return None
    try:
        return json.loads(creds_file.read_text()).get("claudeAiOauth", {}).get("accessToken")
    except ValueError:
        return None


def read_full(creds_file: Path) -> dict | None:
    """Return the whole `.claudeAiOauth` block (accessToken, refreshToken,
    expiresAt, scopes, …) as a dict, or None if the file is absent or unparseable.

    Used to capture the CURRENT live grant (which Claude Code may have rotated)
    before switching away, so the outgoing slot keeps a valid refresh token.

    :param creds_file: Path to Claude Code's `.credentials.json`.
    :return: The `.claudeAiOauth` block dict, or None.
    """
    if not creds_file.is_file():
        return None
    try:
        data = json.loads(creds_file.read_text())
    except ValueError:
        return None
    block = data.get("claudeAiOauth")
    return block if isinstance(block, dict) else None
