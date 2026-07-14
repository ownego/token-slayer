"""Cross-platform active-credential read/write: dispatches to the macOS
Keychain or a merged JSON file depending on `sys.platform`, per how Claude
Code itself stores credentials on each OS (see `.ai/domain/credstore.md`)."""
from __future__ import annotations
import sys
from slayer_cli.credstore import claude_json
from slayer_cli.platform.paths import Paths

__all__ = ["claude_json", "read_active_token", "write_active_token"]


def read_active_token(paths: Paths) -> str | None:
    """Return the currently active Claude Code access token, or None if unset.

    :param paths: Resolved OS paths (honors `CLAUDE_CONFIG_DIR`).
    :return: The active `sk-ant-oat01-…` token, or None.
    """
    if sys.platform == "darwin":
        from slayer_cli.credstore import keychain_store

        return keychain_store.read()
    from slayer_cli.credstore import file_store

    return file_store.read(paths.claude_credentials_file)


def write_active_token(paths: Paths, token: str) -> None:
    """Install `token` as Claude Code's active credential for this OS.

    :param paths: Resolved OS paths (honors `CLAUDE_CONFIG_DIR`).
    :param token: Raw `sk-ant-oat01-…` access token to install as active.
    :return: None
    """
    if sys.platform == "darwin":
        from slayer_cli.credstore import keychain_store

        keychain_store.write(token)
        return
    from slayer_cli.credstore import file_store

    file_store.write(paths.claude_credentials_file, token)
