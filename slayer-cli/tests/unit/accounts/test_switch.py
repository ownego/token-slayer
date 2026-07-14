"""Tests for the switch service (`accounts.switch.switch_to`)."""
from __future__ import annotations

import pytest

from slayer_cli.accounts import switch as switch_mod
from slayer_cli.accounts.store import AccountStore
from slayer_cli.errors import AccountNotFound
from slayer_cli.models.account import Account
from slayer_cli.platform.paths import Paths


def _stub_credstore(monkeypatch, calls):
    """Stub the credstore side (token write + .claude.json patch) so the real
    ~/.claude is never touched, capturing the token and org each received.

    :param monkeypatch: pytest monkeypatch fixture.
    :param calls: Dict to record captured values into.
    :return: None
    """
    monkeypatch.setattr(switch_mod.credstore, "write_active_token",
                        lambda paths, tok: calls.__setitem__("token", tok))
    monkeypatch.setattr(switch_mod.credstore.claude_json, "patch_oauth_account",
                        lambda paths, email, uuid, org: calls.__setitem__("org", org))


def test_switch_writes_creds_state_provider_history(tmp_path, monkeypatch):
    """switch_to writes creds, patches .claude.json, updates state, writes
    provider active.json, and appends a history entry — in that order."""
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    store = AccountStore(p)
    store.add(Account(name="oedev", email="a@b.com", org_uuid="0b3d6883", plan=None,
                      token="sk-ant-oat01-X", added_at=1))
    calls = {}
    _stub_credstore(monkeypatch, calls)
    acc = switch_mod.switch_to(store, "oedev", paths=p)
    assert acc.name == "oedev"
    assert calls["token"] == "sk-ant-oat01-X" and calls["org"] == "0b3d6883"
    assert store.active() == "oedev"
    assert p.active_file.exists()                    # provider written
    assert switch_mod.SwapHistory(p).recent(1)[0].to == "oedev"


def test_switch_re_beacons_missing_org_and_persists_it(tmp_path, monkeypatch):
    """An org-less slot gets its org_uuid re-beaconed, persisted back to the
    slot, and written into active.json."""
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    store = AccountStore(p)
    store.add(Account(name="oedev", email="a@b.com", org_uuid=None, plan=None,
                      token="sk-ant-oat01-X", added_at=1))
    calls = {}
    _stub_credstore(monkeypatch, calls)
    monkeypatch.setattr(switch_mod.beacon, "resolve_org_uuid",
                        lambda token: "0b3d6883")
    acc = switch_mod.switch_to(store, "oedev", paths=p)
    assert acc.org_uuid == "0b3d6883"
    assert calls["org"] == "0b3d6883"                # patched into .claude.json
    assert p.active_file.exists()                    # attribution written
    assert store.get("oedev").org_uuid == "0b3d6883"  # persisted back to slot
    assert store.active() == "oedev"


def test_switch_removes_stale_active_json_when_org_unresolvable(tmp_path, monkeypatch):
    """When the org can't be resolved, a pre-existing (stale) active.json is
    removed rather than left pointing at the previous account, and the
    credential switch still happens."""
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    store = AccountStore(p)
    # A stale active.json on disk naming a DIFFERENT (previous) org.
    p.provider_dir.mkdir(parents=True, exist_ok=True)
    p.active_file.write_text('{"org_uuid": "AAAA-previous", "email": "old@b.com", '
                             '"uuid": null, "source": "switcher"}')
    store.add(Account(name="oedev", email="a@b.com", org_uuid=None, plan=None,
                      token="sk-ant-oat01-X", added_at=1))
    calls = {}
    _stub_credstore(monkeypatch, calls)
    monkeypatch.setattr(switch_mod.beacon, "resolve_org_uuid",
                        lambda token: None)
    switch_mod.switch_to(store, "oedev", paths=p)
    assert not p.active_file.exists()                # stale file removed
    assert store.active() == "oedev"                 # credential switch happened


def test_switch_to_missing_slot_raises_account_not_found(tmp_path, monkeypatch):
    """switch_to raises AccountNotFound when the named slot doesn't exist."""
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    store = AccountStore(p)
    with pytest.raises(AccountNotFound):
        switch_mod.switch_to(store, "ghost", paths=p)
