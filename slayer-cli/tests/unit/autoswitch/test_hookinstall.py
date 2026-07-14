"""Coexistence tests for `autoswitch.hookinstall`: our by-signature entries
must be upserted into settings.json alongside any foreign hooks and non-hook
keys, and `uninstall` must remove only ours."""
from __future__ import annotations

import json

from slayer_cli.autoswitch import hookinstall
from slayer_cli.platform.paths import Paths


def test_install_coexists_and_uninstall_leaves_others(tmp_path, monkeypatch):
    """install() adds our Stop entry without disturbing a foreign hook or a
    non-hook key; uninstall() then removes only ours, leaving both intact."""
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    settings = p._claude_config_dir  # ~/.claude
    settings.mkdir(parents=True, exist_ok=True)
    sfile = p.settings_file
    # Pre-existing foreign hook + a non-hook key must survive.
    sfile.write_text(json.dumps({"model": "opus", "hooks": {"Stop": [
        {"matcher": ".*", "hooks": [{"type": "command", "command": "other-tool report"}]}]}}))
    hookinstall.install(p)
    data = json.loads(sfile.read_text())
    assert data["model"] == "opus"                                     # non-hook key survives
    stop_cmds = [h["command"] for e in data["hooks"]["Stop"] for h in e["hooks"]]
    assert "other-tool report" in stop_cmds                            # foreign hook survives
    assert any("token-slayer hook stop" in c for c in stop_cmds)       # ours added
    assert hookinstall.installed(p) is True
    hookinstall.uninstall(p)
    data2 = json.loads(sfile.read_text())
    stop_cmds2 = [h["command"] for e in data2["hooks"].get("Stop", []) for h in e["hooks"]]
    assert "other-tool report" in stop_cmds2 and not any("token-slayer" in c for c in stop_cmds2)


def test_install_is_idempotent(tmp_path, monkeypatch):
    """install() called twice must not duplicate our entries; second install
    should replace the first without adding another copy."""
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    hookinstall.install(p)
    hookinstall.install(p)  # second install must not duplicate
    data = json.loads(p.settings_file.read_text())
    stop_cmds = [h["command"] for e in data["hooks"]["Stop"] for h in e["hooks"]]
    assert stop_cmds.count("token-slayer hook stop") == 1


def test_install_preserves_foreign_hooks_on_other_events(tmp_path, monkeypatch):
    """install() and uninstall() must preserve foreign hooks on non-Stop events
    (e.g., SessionStart), proving per-event loop preserves foreign entries."""
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    settings = p._claude_config_dir
    settings.mkdir(parents=True, exist_ok=True)
    sfile = p.settings_file
    # Pre-seed a foreign hook on SessionStart (non-Stop event).
    sfile.write_text(json.dumps({"hooks": {"SessionStart": [
        {"matcher": ".*", "hooks": [{"type": "command", "command": "other-tool onstart"}]}]}}))
    hookinstall.install(p)
    data = json.loads(sfile.read_text())
    # Foreign hook on SessionStart must survive.
    session_cmds = [h["command"] for e in data["hooks"].get("SessionStart", []) for h in e["hooks"]]
    assert "other-tool onstart" in session_cmds
    # Our hooks added on all events.
    assert hookinstall.installed(p) is True
    hookinstall.uninstall(p)
    data2 = json.loads(sfile.read_text())
    # Foreign hook on SessionStart must still survive uninstall.
    session_cmds2 = [h["command"] for e in data2["hooks"].get("SessionStart", []) for h in e["hooks"]]
    assert "other-tool onstart" in session_cmds2 and not any("token-slayer" in c for c in session_cmds2)


def test_install_sets_settings_file_mode_0644(tmp_path, monkeypatch):
    """install() must write settings.json with mode 0644 (readable by others,
    writable only by owner), matching Claude Code's own file permissions."""
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    hookinstall.install(p)
    file_mode = p.settings_file.stat().st_mode & 0o777
    assert file_mode == 0o644


def test_install_creates_settings_file_from_scratch(tmp_path, monkeypatch):
    """install() on a namespace with no pre-existing settings.json must not
    crash and must produce a valid, parseable settings file."""
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    # Ensure no settings.json exists.
    assert not p.settings_file.exists()
    hookinstall.install(p)
    # File must exist and be valid JSON.
    assert p.settings_file.exists()
    data = json.loads(p.settings_file.read_text())
    assert isinstance(data, dict)
    assert "hooks" in data
    # All our specs must be present.
    assert hookinstall.installed(p) is True


def test_install_honors_ts_hook_bin_override(tmp_path, monkeypatch):
    """TS_HOOK_BIN overrides the hook command's binary, so Claude's hooks reach
    THIS CLI even when a different `token-slayer` (the attribution shim) shadows
    it on PATH; installed() still detects the overridden command."""
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.setenv("TS_HOOK_BIN", "/opt/venv/bin/slayer")
    p = Paths("token_slayer")
    hookinstall.install(p)
    data = json.loads(p.settings_file.read_text())
    stop_cmds = [h["command"] for e in data["hooks"]["Stop"] for h in e["hooks"]]
    assert any(c == "/opt/venv/bin/slayer hook stop" for c in stop_cmds)
    assert hookinstall.installed(p) is True
