"""Tests for `accounts.attribution.reconcile_active` — points the hook's
attribution files at the account that just became active."""
from __future__ import annotations

import json

from slayer_cli.accounts import attribution
from slayer_cli.models.account import Account
from slayer_cli.platform.paths import Paths


def _paths(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    return Paths("token_slayer")


def test_reconcile_writes_provider_and_patches_oauth_when_org_present(tmp_path, monkeypatch):
    """With an org_uuid, reconcile writes account-provider/active.json AND
    patches .claude.json oauthAccount so both hook sources agree."""
    p = _paths(tmp_path, monkeypatch)
    acc = Account(name="work@x.com", email="work@x.com", uuid="u-1", org_uuid="org-1",
                  plan=None, token="sk-ant-oat01-W", added_at=1)
    attribution.reconcile_active(p, acc)

    provider = json.loads(p.active_file.read_text())
    assert provider["email"] == "work@x.com"
    assert provider["org_uuid"] == "org-1"

    oauth = json.loads(p.claude_json.read_text())["oauthAccount"]
    assert oauth["emailAddress"] == "work@x.com"


def test_reconcile_clears_provider_when_org_missing(tmp_path, monkeypatch):
    """Without an org_uuid the provider file can't be written correctly; a
    stale one is removed so the hook degrades rather than misattributing."""
    p = _paths(tmp_path, monkeypatch)
    p.active_file.parent.mkdir(parents=True, exist_ok=True)
    p.active_file.write_text('{"email":"stale@x.com","org_uuid":"old"}')

    acc = Account(name="new", email="new@x.com", uuid=None, org_uuid=None,
                  plan=None, token="sk-ant-oat01-N", added_at=1)
    attribution.reconcile_active(p, acc)

    assert not p.active_file.exists()  # stale provider file removed
