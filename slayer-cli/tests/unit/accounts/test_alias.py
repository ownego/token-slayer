import pytest
from slayer_cli.accounts import alias
from slayer_cli.accounts.store import AccountStore
from slayer_cli.errors import AccountNotFound
from slayer_cli.models.account import Account
from slayer_cli.platform.paths import Paths


def _store(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    return AccountStore(Paths("token_slayer"))


def _acc(name, **kw):
    return Account(name=name, token="sk-ant-oat01-TESTTOKEN", added_at=1, **kw)


def test_validate_alias_rules():
    alias.validate_alias("work")           # ok
    alias.validate_alias("w-2")            # ok
    for bad in ["", "1work", "Work", "a_b", "with space", "x" * 21]:
        with pytest.raises(alias.InvalidAlias):
            alias.validate_alias(bad)


def test_resolve_by_name_alias_email(tmp_path, monkeypatch):
    store = _store(tmp_path, monkeypatch)
    store.add(_acc("acc1", email="a@b.com", alias="work"))
    assert store.resolve("acc1").name == "acc1"    # slot name
    assert store.resolve("work").name == "acc1"    # alias
    assert store.resolve("a@b.com").name == "acc1"  # email
    with pytest.raises(AccountNotFound):
        store.resolve("nope")


def test_set_alias_validates_and_enforces_uniqueness(tmp_path, monkeypatch):
    store = _store(tmp_path, monkeypatch)
    store.add(_acc("acc1"))
    store.add(_acc("acc2"))
    store.set_alias("acc1", "work")
    assert store.get("acc1").alias == "work"
    with pytest.raises(alias.AliasInUse):
        store.set_alias("acc2", "work")            # taken
    store.set_alias("acc1", None)                  # clear
    assert store.get("acc1").alias is None
