"""Tests for `teardown.uninstall` — reversible removal of the switcher's
local footprint (venv, shim, symlink, attribution file, account state) and
restoration of the user's pristine pre-slayer Claude login."""
from __future__ import annotations

import json

from slayer_cli import credstore, teardown
from slayer_cli.platform.paths import Paths


def _seed_switcher_footprint(p: Paths) -> None:
    """Create a fake on-disk switcher install under `p` for uninstall to tear down.

    :param p: Resolved paths rooted at the monkeypatched HOME.
    :return: None
    """
    p.accounts_dir.mkdir(parents=True)
    (p.accounts_dir / "oedev.json").write_text("{}")
    p.state_file.parent.mkdir(parents=True, exist_ok=True)
    p.state_file.write_text(json.dumps({"active_slot": "oedev"}))
    p.history_file.write_text('{"event": "switch"}\n')
    p.usage_cache_dir.mkdir(parents=True)
    (p.usage_cache_dir / "oedev.json").write_text("{}")
    p.provider_dir.mkdir(parents=True, exist_ok=True)
    p.active_file.write_text(json.dumps({"org_uuid": "o1"}))
    venv_dir = p.config_dir / "venv"
    (venv_dir / "bin").mkdir(parents=True)
    (venv_dir / "bin" / "python").write_text("#!/bin/sh\n")
    local_bin = p.home / ".local" / "bin"
    local_bin.mkdir(parents=True)
    (local_bin / "token-slayer").write_text("#!/bin/sh\nexec true\n")
    (local_bin / "slayer").write_text("#!/bin/sh\nexec true\n")


def test_uninstall_restores_backup_and_removes_everything(tmp_path, monkeypatch):
    """Full uninstall (no --keep-accounts) restores the pristine credential and
    removes the venv, shim, symlink, attribution file, and all account state."""
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    p = Paths("token_slayer")
    p.claude_credentials_file.parent.mkdir(parents=True)
    original = json.dumps({"claudeAiOauth": {"accessToken": "sk-ant-oat01-ORIGINAL"}})
    p.claude_credentials_file.write_text(original)
    credstore.write_active_token(p, "sk-ant-oat01-SWITCHED")
    original_backup = json.loads(p.claude_credentials_backup.read_text())
    _seed_switcher_footprint(p)

    summary = teardown.uninstall(p)

    assert summary.credential_restored is True
    assert summary.kept_accounts is False
    assert json.loads(p.claude_credentials_file.read_text()) == original_backup
    assert not p.claude_credentials_backup.exists()
    assert not (p.config_dir / "venv").exists()
    assert not (p.home / ".local" / "bin" / "token-slayer").exists()
    assert not (p.home / ".local" / "bin" / "slayer").exists()
    assert not p.active_file.exists()
    assert not p.accounts_dir.exists()
    assert not p.state_file.exists()
    assert not p.history_file.exists()
    assert not p.usage_cache_dir.exists()
    assert summary.removed  # non-empty, human-readable list
    assert not any("sk-ant-oat01" in item for item in summary.removed)


def test_uninstall_keep_accounts_preserves_slots_and_state(tmp_path, monkeypatch):
    """`keep_accounts=True` preserves accounts_dir + state_file but still clears
    the usage cache and the attribution file."""
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    p = Paths("token_slayer")
    _seed_switcher_footprint(p)

    summary = teardown.uninstall(p, keep_accounts=True)

    assert summary.kept_accounts is True
    assert p.accounts_dir.exists()
    assert (p.accounts_dir / "oedev.json").exists()
    assert p.state_file.exists()
    assert not p.usage_cache_dir.exists()
    assert not p.active_file.exists()
    assert not (p.config_dir / "venv").exists()


def test_uninstall_with_no_backup_leaves_credential_untouched(tmp_path, monkeypatch):
    """When no `.slayer-bak` backup exists, `credential_restored` is False, no
    error is raised, and any existing `.credentials.json` is left as-is."""
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    p = Paths("token_slayer")
    p.claude_credentials_file.parent.mkdir(parents=True)
    current = json.dumps({"claudeAiOauth": {"accessToken": "sk-ant-oat01-CURRENT"}})
    p.claude_credentials_file.write_text(current)

    summary = teardown.uninstall(p)

    assert summary.credential_restored is False
    assert p.claude_credentials_file.read_text() == current
