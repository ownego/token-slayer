"""macOS active-credential storage: Claude Code keeps NO credentials file on
macOS, only a Keychain entry (service "Claude Code-credentials"). Only ever
invoked when `sys.platform == "darwin"`."""
from __future__ import annotations
import getpass
import json
import shlex
import time
from slayer_cli.errors import CredentialError
from slayer_cli.platform import process
from slayer_cli.credstore.file_store import SCOPES, ONE_YEAR_MS

SERVICE = "Claude Code-credentials"
"""Keychain service name Claude Code itself uses (community-corroborated)."""


def _account() -> str:
    """Return the Keychain account name to read/write under.

    :return: The current OS user name.
    """
    return getpass.getuser()


def _read_raw() -> str | None:
    """Return the raw JSON blob stored in the Keychain entry, or None if absent.

    :return: The stored password value, or None.
    """
    rc, out, _err = process.run(["security", "find-generic-password", "-s", SERVICE, "-w"])
    if rc != 0:
        return None
    return out.strip() or None


def write(token: str) -> None:
    """Merge `token` into the Keychain entry's `.claudeAiOauth`, preserving other keys.

    :param token: Raw `sk-ant-oat01-…` access token to install as active.
    :return: None
    """
    data: dict = {}
    raw = _read_raw()
    if raw:
        try:
            data = json.loads(raw)
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
    blob = json.dumps(data)
    # Feed the command line via stdin (`security -i`) so the token-bearing blob
    # never appears in argv, where `ps`/`/proc/<pid>/cmdline` would expose it.
    cmd_line = "add-generic-password -U -s {} -a {} -w {}".format(
        shlex.quote(SERVICE), shlex.quote(_account()), shlex.quote(blob)
    )
    rc, _out, err = process.run(["security", "-i"], input_=cmd_line + "\n")
    if rc != 0:
        raise CredentialError(f"failed to write macOS Keychain credential: {err.strip()}")


def read() -> str | None:
    """Return the active access token from the Keychain entry, or None if absent/unreadable.

    :return: The `.claudeAiOauth.accessToken` value, or None.
    """
    raw = _read_raw()
    if raw is None:
        return None
    try:
        return json.loads(raw).get("claudeAiOauth", {}).get("accessToken")
    except ValueError:
        return None
