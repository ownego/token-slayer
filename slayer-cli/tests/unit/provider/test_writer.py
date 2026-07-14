"""Tests for the active.json writer that provides account attribution to the hook."""
from __future__ import annotations
import json
import pytest
from slayer_cli.platform.paths import Paths
from slayer_cli.models.account import Account
from slayer_cli.provider.writer import write_active


def _acc(org: str) -> Account:
    """Create a test account with the given org_uuid."""
    return Account(
        name="oedev",
        email="a@b.com",
        org_uuid=org,
        plan=None,
        token="sk-ant-oat01-x",
        added_at=1,
    )


def test_writes_active_json(tmp_path, monkeypatch):
    """Writes active.json with org_uuid, email, and source fields."""
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    write_active(p, _acc("0b3d6883"))
    d = json.loads(p.active_file.read_text())
    assert d["org_uuid"] == "0b3d6883"
    assert d["email"] == "a@b.com"
    assert d["source"] == "switcher"


def test_blank_org_uuid_rejected(tmp_path, monkeypatch):
    """Raises ValidationError when org_uuid is blank."""
    monkeypatch.setenv("HOME", str(tmp_path))
    with pytest.raises(Exception):
        write_active(Paths("token_slayer"), _acc(""))
