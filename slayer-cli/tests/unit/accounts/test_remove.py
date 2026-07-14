"""Tests for `accounts.remove.remove_account` — deleting an account slot
without leaving a dangling active pointer.

Real gap this closes: `AccountStore.remove()` is documented as "never
touches state.json — a dangling pointer the caller is responsible for
handling", but the CLI's `remove` command never handled it. Deleting the
currently-active slot left `state.json` and `account-provider/active.json`
(what the hook actually reads for attribution) pointing at an account that
no longer exists — so events kept attributing to the deleted account."""
from __future__ import annotations

import json

from slayer_cli.accounts.remove import remove_account
from slayer_cli.accounts.store import AccountStore
from slayer_cli.models.account import Account
from slayer_cli.platform.paths import Paths


def _paths(tmp_path, monkeypatch) -> Paths:
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    return Paths("token_slayer")


def _account(name: str) -> Account:
    return Account(name=name, email=name, org_uuid=f"org-{name}", uuid=None, plan=None,
                    token="sk-ant-oat01-TESTTOKEN", added_at=1)


def test_removing_the_active_slot_clears_the_active_pointer(tmp_path, monkeypatch):
    """Removing the currently-active slot unsets state.json's active_slot —
    no dangling pointer to a slot that no longer exists."""
    paths = _paths(tmp_path, monkeypatch)
    store = AccountStore(paths)
    store.add(_account("work@x.com"))
    store.set_active("work@x.com")

    remove_account(store, paths, "work@x.com")

    assert store.active() is None


def test_removing_the_active_slot_clears_stale_attribution(tmp_path, monkeypatch):
    """Removing the currently-active slot also clears
    account-provider/active.json -- the file the hook actually reads for
    attribution -- so events don't keep attributing to a deleted account."""
    paths = _paths(tmp_path, monkeypatch)
    store = AccountStore(paths)
    account = _account("work@x.com")
    store.add(account)
    store.set_active("work@x.com")
    paths.active_file.parent.mkdir(parents=True, exist_ok=True)
    paths.active_file.write_text(json.dumps({"org_uuid": account.org_uuid, "email": account.email}))

    remove_account(store, paths, "work@x.com")

    assert not paths.active_file.exists()


def test_removing_a_non_active_slot_leaves_the_active_pointer_alone(tmp_path, monkeypatch):
    """Removing a slot that ISN'T active must not disturb the real active
    account's state or attribution."""
    paths = _paths(tmp_path, monkeypatch)
    store = AccountStore(paths)
    store.add(_account("work@x.com"))
    store.add(_account("home@x.com"))
    store.set_active("home@x.com")
    paths.active_file.parent.mkdir(parents=True, exist_ok=True)
    paths.active_file.write_text(json.dumps({"org_uuid": "org-home@x.com", "email": "home@x.com"}))

    remove_account(store, paths, "work@x.com")

    assert store.active() == "home@x.com"
    assert paths.active_file.exists()


def test_removing_the_active_slot_switches_to_the_remaining_account(tmp_path, monkeypatch):
    """When an account remains after removing the active one, it becomes
    active (a real switch — credential + attribution written for it) rather
    than leaving no account active."""
    paths = _paths(tmp_path, monkeypatch)
    store = AccountStore(paths)
    store.add(_account("work@x.com"))
    store.add(_account("home@x.com"))
    store.set_active("work@x.com")

    remove_account(store, paths, "work@x.com")

    assert store.active() == "home@x.com"
    provider = json.loads(paths.active_file.read_text())
    assert provider["email"] == "home@x.com"


def test_removing_the_active_slot_prefers_the_most_recently_used_remaining_account(tmp_path, monkeypatch):
    """With more than one remaining account, the most-recently-used one
    becomes active — the most likely one the user actually wants next."""
    paths = _paths(tmp_path, monkeypatch)
    store = AccountStore(paths)
    store.add(_account("work@x.com"))
    store.add(_account("home@x.com").model_copy(update={"last_used": 100}))
    store.add(_account("side@x.com").model_copy(update={"last_used": 200}))
    store.set_active("work@x.com")

    remove_account(store, paths, "work@x.com")

    assert store.active() == "side@x.com"
