"""Cross-platform active-credential read/write: dispatches to the macOS
Keychain or a merged JSON file depending on `sys.platform`, per how Claude
Code itself stores credentials on each OS (see `.ai/domain/credstore.md`)."""
from __future__ import annotations
import sys
from slayer_cli.credstore import claude_json
from slayer_cli.platform.paths import Paths

__all__ = ["claude_json", "read_active_token", "read_active_full", "write_active_token", "write_active_full"]


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


def read_active_full(paths: Paths) -> dict | None:
    """Return Claude Code's active `.claudeAiOauth` block, or None if unset.

    :param paths: Resolved OS paths (honors `CLAUDE_CONFIG_DIR`).
    :return: The active oauth block dict, or None.
    """
    if sys.platform == "darwin":
        from slayer_cli.credstore import keychain_store

        return keychain_store.read_full()
    from slayer_cli.credstore import file_store

    return file_store.read_full(paths.claude_credentials_file)


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


def write_active_full(paths: Paths, access_token: str, refresh_token: str, expires_at: int) -> None:
    """Install a full provisioned grant as Claude Code's active credential for this OS.

    :param paths: Resolved OS paths (honors `CLAUDE_CONFIG_DIR`).
    :param access_token: Raw `sk-ant-oat01-…` access token.
    :param refresh_token: Real `ort01-…` refresh token for Claude Code self-refresh.
    :param expires_at: Token expiry time in milliseconds (Unix epoch * 1000).
    :return: None
    """
    if sys.platform == "darwin":
        from slayer_cli.credstore import keychain_store

        keychain_store.write_full(access_token, refresh_token, expires_at)
        return
    from slayer_cli.credstore import file_store

    file_store.write_full(paths.claude_credentials_file, access_token, refresh_token, expires_at)
