"""Tests for `accounts.detect.detect_current` and `accounts.add.{add_snapshot,add_via_login}`."""
from __future__ import annotations

import pytest

from slayer_cli.accounts import add as add_mod
from slayer_cli.accounts import detect as detect_mod
from slayer_cli.accounts.store import AccountStore
from slayer_cli.errors import CredentialError
from slayer_cli.platform.paths import Paths


def _paths(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    return Paths("token_slayer")


def test_detect_current_returns_none_when_no_active_token(tmp_path, monkeypatch):
    """No active credential on disk -> detect_current returns None."""
    p = _paths(tmp_path, monkeypatch)
    monkeypatch.setattr(detect_mod.credstore, "read_active_token", lambda paths: None)
    assert detect_mod.detect_current(p) is None


def test_detect_current_builds_account_from_token_beacon_and_claude_json(tmp_path, monkeypatch):
    """detect_current reads the active token, beacons the org uuid, and
    fills email/uuid from .claude.json's oauthAccount block."""
    p = _paths(tmp_path, monkeypatch)
    monkeypatch.setattr(detect_mod.credstore, "read_active_token", lambda paths: "sk-ant-oat01-CUR")
    monkeypatch.setattr(detect_mod.beacon, "resolve_org_uuid", lambda token: "org-abc")
    monkeypatch.setattr(
        detect_mod.credstore.claude_json,
        "read_oauth_account",
        lambda paths: {"emailAddress": "dev@example.com", "accountUuid": "uuid-1"},
    )

    acc = detect_mod.detect_current(p)

    assert acc is not None
    assert acc.name == "dev"
    assert acc.email == "dev@example.com"
    assert acc.uuid == "uuid-1"
    assert acc.org_uuid == "org-abc"
    assert acc.token == "sk-ant-oat01-CUR"


def test_detect_current_defaults_name_when_no_email(tmp_path, monkeypatch):
    """No email in .claude.json -> name falls back to 'default'."""
    p = _paths(tmp_path, monkeypatch)
    monkeypatch.setattr(detect_mod.credstore, "read_active_token", lambda paths: "sk-ant-oat01-CUR")
    monkeypatch.setattr(detect_mod.beacon, "resolve_org_uuid", lambda token: None)
    monkeypatch.setattr(detect_mod.credstore.claude_json, "read_oauth_account", lambda paths: {})

    acc = detect_mod.detect_current(p)

    assert acc.name == "default"
    assert acc.email is None
    assert acc.org_uuid is None


def test_add_snapshot_stores_slot_with_org_uuid(tmp_path, monkeypatch):
    """add_snapshot reads the active token + beacon org + .claude.json email,
    and persists a slot the store can retrieve."""
    p = _paths(tmp_path, monkeypatch)
    store = AccountStore(p)
    monkeypatch.setattr(add_mod.credstore, "read_active_token", lambda paths: "sk-ant-oat01-SNAP")
    monkeypatch.setattr(add_mod.beacon, "resolve_org_uuid", lambda token: "org-snap")
    monkeypatch.setattr(
        add_mod.credstore.claude_json,
        "read_oauth_account",
        lambda paths: {"emailAddress": "snap@example.com", "accountUuid": "uuid-snap"},
    )

    acc = add_mod.add_snapshot(store, p, "oedev")

    assert acc.name == "oedev"
    assert acc.org_uuid == "org-snap"
    assert acc.email == "snap@example.com"
    assert acc.token == "sk-ant-oat01-SNAP"
    assert store.get("oedev").org_uuid == "org-snap"


def test_add_snapshot_raises_when_no_active_token(tmp_path, monkeypatch):
    """No active credential -> nothing to snapshot, raises CredentialError."""
    p = _paths(tmp_path, monkeypatch)
    store = AccountStore(p)
    monkeypatch.setattr(add_mod.credstore, "read_active_token", lambda paths: None)

    with pytest.raises(CredentialError):
        add_mod.add_snapshot(store, p, "oedev")


def test_add_via_login_runs_pkce_and_stores_slot_without_stale_claude_json(tmp_path, monkeypatch):
    """add_via_login drives PKCE via the injected code_provider (called with
    the authorize URL), exchanges the pasted code, beacons the org, and
    stores a slot WITHOUT reading .claude.json (would be stale post-login)."""
    p = _paths(tmp_path, monkeypatch)
    store = AccountStore(p)

    monkeypatch.setattr(
        add_mod.pkce, "start", lambda: ("https://claude.com/cai/oauth/authorize?x=1", "verifier-1", "state-1")
    )
    monkeypatch.setattr(add_mod.pkce, "exchange", lambda code, verifier, client=None: "sk-ant-oat01-LOGIN")
    monkeypatch.setattr(add_mod.beacon, "resolve_org_uuid", lambda token: "org-login")

    claude_json_calls = []
    monkeypatch.setattr(
        add_mod.credstore.claude_json,
        "read_oauth_account",
        lambda paths: claude_json_calls.append(paths) or {"emailAddress": "stale@example.com"},
    )

    seen_urls = []

    def code_provider(url: str) -> str:
        seen_urls.append(url)
        return "PASTEDCODE#PASTEDSTATE"

    acc = add_mod.add_via_login(store, p, "newlogin", code_provider)

    assert seen_urls == ["https://claude.com/cai/oauth/authorize?x=1"]
    assert acc.name == "newlogin"
    assert acc.org_uuid == "org-login"
    assert acc.token == "sk-ant-oat01-LOGIN"
    assert acc.email is None
    assert acc.uuid is None
    assert claude_json_calls == []  # never consults .claude.json (stale post-login)
    assert store.get("newlogin").org_uuid == "org-login"


def test_detect_current_captures_refresh_and_expiry_from_full_grant(tmp_path, monkeypatch):
    """detect_current sources the FULL live grant (access + refresh + expiry) so
    a base slot built from it can self-refresh, not just the bare access token."""
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    monkeypatch.setattr(detect_mod.credstore, "read_active_full",
                        lambda paths: {"accessToken": "sk-ant-oat01-CUR",
                                       "refreshToken": "ort01-CUR", "expiresAt": 1_800_000_000_000})
    monkeypatch.setattr(detect_mod.beacon, "resolve_org_uuid", lambda token: "org-x")
    monkeypatch.setattr(detect_mod.credstore.claude_json, "read_oauth_account",
                        lambda paths: {"emailAddress": "me@x.com", "accountUuid": "u-1"})
    acc = detect_mod.detect_current(p)
    assert acc is not None
    assert acc.token == "sk-ant-oat01-CUR"
    assert acc.refresh_token == "ort01-CUR"
    assert acc.expires_at == 1_800_000_000_000
