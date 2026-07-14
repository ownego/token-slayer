"""Tests for `accounts.base.add_base_account` — the install-time snapshot of the
machine's current Claude login as a base account slot (idempotent)."""
from __future__ import annotations

from slayer_cli.accounts import base as base_mod
from slayer_cli.accounts.store import AccountStore
from slayer_cli.models.account import Account
from slayer_cli.platform.paths import Paths


def _paths(tmp_path, monkeypatch):
    """Isolated HOME + AccountStore."""
    monkeypatch.setenv("HOME", str(tmp_path))
    return Paths("token_slayer")


def _stub_detect(monkeypatch, account):
    """Make detect_current return `account` (or None)."""
    monkeypatch.setattr(base_mod, "detect_current", lambda paths: account)


def test_add_base_none_when_no_active_login(tmp_path, monkeypatch):
    """No active Claude login → (None, 'none'), nothing written."""
    p = _paths(tmp_path, monkeypatch)
    _stub_detect(monkeypatch, None)
    account, status = base_mod.add_base_account(AccountStore(p), p)
    assert account is None and status == "none"
    assert AccountStore(p).list() == []


def test_add_base_adds_and_activates_when_absent(tmp_path, monkeypatch):
    """A fresh login is stored (named by full email) and marked active."""
    p = _paths(tmp_path, monkeypatch)
    detected = Account(name="tungot.work", email="tungot.work@gmail.com", uuid="u-1",
                       org_uuid="org-1", plan=None, token="sk-ant-oat01-CUR", added_at=1)
    _stub_detect(monkeypatch, detected)
    store = AccountStore(p)
    account, status = base_mod.add_base_account(store, p)
    assert status == "added"
    assert account.name == "tungot.work@gmail.com"  # named by full email
    fresh = AccountStore(p)
    assert [a.name for a in fresh.list()] == ["tungot.work@gmail.com"]
    assert fresh.active() == "tungot.work@gmail.com"
    # attribution reconciled: provider active.json points at the base account
    import json
    provider = json.loads(p.active_file.read_text())
    assert provider["email"] == "tungot.work@gmail.com"


def test_add_base_skips_when_identity_already_tracked(tmp_path, monkeypatch):
    """An existing slot with the SAME identity (cache_key) → (existing, 'exists'),
    no duplicate slot even if the slot name differs."""
    p = _paths(tmp_path, monkeypatch)
    store = AccountStore(p)
    # already-tracked slot for the same account, but under a different name
    store.add(Account(name="work", email="tungot.work@gmail.com", uuid="u-1",
                      org_uuid="org-1", plan=None, token="sk-ant-oat01-OLD", added_at=1))
    detected = Account(name="tungot.work", email="tungot.work@gmail.com", uuid="u-1",
                       org_uuid="org-1", plan=None, token="sk-ant-oat01-CUR", added_at=2)
    _stub_detect(monkeypatch, detected)
    account, status = base_mod.add_base_account(store, p)
    assert status == "exists"
    assert account.name == "work"  # returns the existing slot
    assert len(AccountStore(p).list()) == 1  # no duplicate


def test_add_base_preserves_existing_active(tmp_path, monkeypatch):
    """When a slot is already active, adding a NEW base account does not steal active."""
    p = _paths(tmp_path, monkeypatch)
    store = AccountStore(p)
    store.add(Account(name="other@x.com", email="other@x.com", uuid="u-2", org_uuid="org-2",
                      plan=None, token="sk-ant-oat01-OTHER", added_at=1))
    store.set_active("other@x.com")
    detected = Account(name="me", email="me@x.com", uuid="u-3", org_uuid="org-3",
                       plan=None, token="sk-ant-oat01-ME", added_at=2)
    _stub_detect(monkeypatch, detected)
    _, status = base_mod.add_base_account(store, p)
    assert status == "added"
    assert AccountStore(p).active() == "other@x.com"  # unchanged


def test_add_base_dedups_by_email_even_when_cache_keys_differ(tmp_path, monkeypatch):
    """A provisioned slot (uuid=None → org-only key) and the detected login
    (uuid present → uuid|org key) are the SAME account by email; add_base must
    treat it as 'exists' and NOT overwrite the provisioned slot (which would
    clobber its refresh token)."""
    p = _paths(tmp_path, monkeypatch)
    store = AccountStore(p)
    # provisioned slot: uuid=None, HAS a refresh token, named by email
    store.add(Account(name="work@x.com", email="work@x.com", uuid=None, org_uuid="org-1",
                      plan=None, token="sk-ant-oat01-P", refresh_token="ort01-P",
                      expires_at=9_000_000_000_000, added_at=1))
    # detected current login: SAME email, but uuid present and NO refresh token
    detected = Account(name="work", email="work@x.com", uuid="UUID-W", org_uuid="org-1",
                       plan=None, token="sk-ant-oat01-D", refresh_token=None, added_at=2)
    _stub_detect(monkeypatch, detected)
    account, status = base_mod.add_base_account(store, p)
    assert status == "exists"
    # the provisioned slot survives intact — refresh token NOT clobbered
    kept = AccountStore(p).get("work@x.com")
    assert kept.refresh_token == "ort01-P"
    assert len(AccountStore(p).list()) == 1
