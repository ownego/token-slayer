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
    sfile = settings / "settings.json"
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
