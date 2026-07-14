"""Patches Claude Code's own `~/.claude.json` `.oauthAccount` block so its
account display matches the slot slayer-cli just switched to. Not part of the
ccm shell tool — this fixes the `.claude.json`-vs-token split seen in the
oedev debug."""
from __future__ import annotations
import json
from slayer_cli.errors import CredentialError
from slayer_cli.platform.paths import Paths


def read_oauth_account(paths: Paths) -> dict:
    """Return the `.oauthAccount` block from `~/.claude.json`, or {} if absent/unreadable.

    :param paths: Resolved OS paths (honors `CLAUDE_CONFIG_DIR`).
    :return: The `oauthAccount` dict, or {}.
    """
    if not paths.claude_json.is_file():
        return {}
    try:
        data = json.loads(paths.claude_json.read_text())
    except ValueError:
        return {}
    return data.get("oauthAccount", {})


def patch_oauth_account(paths: Paths, email: str | None, uuid: str | None, org: str | None) -> None:
    """Merge `.oauthAccount` into `~/.claude.json`, preserving all other keys.

    Writes atomically (temp file + replace). Unlike the credential file,
    `.claude.json` permissions are left untouched — Claude Code itself does
    not keep this file at 0600.

    :param paths: Resolved OS paths (honors `CLAUDE_CONFIG_DIR`).
    :param email: Account email address to record.
    :param uuid: Account UUID to record.
    :param org: Organization UUID to record.
    :return: None
    """
    data: dict = {}
    if paths.claude_json.is_file():
        try:
            data = json.loads(paths.claude_json.read_text())
        except ValueError:
            data = {}
    data["oauthAccount"] = {
        "emailAddress": email,
        "accountUuid": uuid,
        "organizationUuid": org,
    }
    try:
        paths.claude_json.parent.mkdir(parents=True, exist_ok=True)
        tmp = paths.claude_json.with_suffix(".tmp")
        tmp.write_text(json.dumps(data, indent=2))
        tmp.replace(paths.claude_json)
    except OSError as exc:
        raise CredentialError(f"failed to write {paths.claude_json}") from exc
