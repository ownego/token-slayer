"""Reversible teardown of the switcher's local footprint.

Restores the user's pristine pre-slayer Claude login (when a `.slayer-bak`
backup exists) and removes the switcher's own files: the venv, the
`token-slayer`/`slayer` shim + symlink, the attribution file, and — unless
`keep_accounts` is set — the stored account slots, switch state, swap
history, and usage cache.

Deliberately OUT of scope: the token-slayer event-tracking HOOK footprint
(`send-hook.sh`, the hook token, `detector-config`, `custom.sh`, the
shell-rc block, `version`). That is the event-tracking product, not the
account switcher, and its teardown is a separate manual step today (a
future `--hooks` flag could fold it in).
"""
from __future__ import annotations

import shutil
import sys
from dataclasses import dataclass, field

from slayer_cli.credstore import file_store
from slayer_cli.platform.paths import Paths


@dataclass
class UninstallSummary:
    """What `uninstall` did. Never carries a token or credential contents.

    :var credential_restored: True if the pristine pre-slayer credential was
        restored from its `.slayer-bak` backup.
    :var removed: Human-readable descriptions of items removed.
    :var kept_accounts: True if `keep_accounts` preserved the account slots
        and switch state.
    :var notes: Extra human-readable notes (e.g. macOS Keychain not touched).
    """

    credential_restored: bool
    removed: list[str] = field(default_factory=list)
    kept_accounts: bool = False
    notes: list[str] = field(default_factory=list)


def uninstall(paths: Paths, *, keep_accounts: bool = False) -> UninstallSummary:
    """Reversibly tear down the switcher: restore the original login first,
    then remove the switcher's local footprint.

    Every removal step is tolerant of a missing item (`missing_ok=True` /
    `ignore_errors=True`) — a partially-installed or already-cleaned-up
    switcher is not an error. The credential restore happens FIRST so a
    failure partway through removal still leaves the user's original login
    usable.

    :param paths: Resolved OS paths for the active namespace.
    :param keep_accounts: If True, preserve the account slots and switch
        state (`accounts_dir`, `state_file`); the usage cache and
        attribution file are still removed either way, since both are
        regenerable/derived rather than user data.
    :return: Summary of what was restored/removed. Never contains a token.
    """
    removed: list[str] = []
    notes: list[str] = []

    if sys.platform == "darwin":
        # macOS never gets a file backup (credentials live in the system
        # Keychain, written by credstore.keychain_store) — nothing to
        # restore, and we must not touch the Keychain here.
        credential_restored = False
        notes.append(
            "macOS: the original login lives in the system Keychain, not a "
            "file backup — it was left untouched."
        )
    else:
        credential_restored = file_store.restore_backup(paths.claude_credentials_file)
        if credential_restored:
            removed.append("original Claude login restored from backup")

    paths.active_file.unlink(missing_ok=True)
    removed.append("attribution file (active.json)")

    # Linux: removing the venv directory while the currently-running shim's
    # python interpreter is still executing from it is safe — the process
    # holds the unlinked inodes open until it exits.
    venv_dir = paths.config_dir / "venv"
    shutil.rmtree(venv_dir, ignore_errors=True)
    removed.append("venv")

    shim = paths.home / ".local" / "bin" / "token-slayer"
    symlink = paths.home / ".local" / "bin" / "slayer"
    shim.unlink(missing_ok=True)
    symlink.unlink(missing_ok=True)
    removed.append("shim (token-slayer)")
    removed.append("symlink (slayer)")

    if keep_accounts:
        shutil.rmtree(paths.usage_cache_dir, ignore_errors=True)
        removed.append("usage cache")
    else:
        shutil.rmtree(paths.accounts_dir, ignore_errors=True)
        paths.state_file.unlink(missing_ok=True)
        paths.history_file.unlink(missing_ok=True)
        shutil.rmtree(paths.usage_cache_dir, ignore_errors=True)
        removed.append("account slots")
        removed.append("switch state")
        removed.append("swap history")
        removed.append("usage cache")

    return UninstallSummary(
        credential_restored=credential_restored,
        removed=removed,
        kept_accounts=keep_accounts,
        notes=notes,
    )
