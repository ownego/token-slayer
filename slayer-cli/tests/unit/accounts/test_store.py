import stat
import pytest
from slayer_cli.platform.paths import Paths
from slayer_cli.models.account import Account
from slayer_cli.accounts.store import AccountStore
from slayer_cli.errors import AccountNotFound


def _acc(name):
    return Account(
        name=name,
        email=None,
        org_uuid="o-" + name,
        plan=None,
        token="sk-ant-oat01-" + name,
        added_at=1,
        last_used=None,
    )


def test_add_get_list_remove_active(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    s = AccountStore(Paths("token_slayer"))
    s.add(_acc("oedev"))
    s.add(_acc("clone"))
    assert [a.name for a in s.list()] == ["clone", "oedev"]
    assert s.get("oedev").org_uuid == "o-oedev"
    f = Paths("token_slayer").accounts_dir / "oedev.json"
    assert stat.S_IMODE(f.stat().st_mode) == 0o600
    assert stat.S_IMODE(Paths("token_slayer").accounts_dir.stat().st_mode) == 0o700
    s.set_active("oedev")
    assert s.active() == "oedev"
    s.remove("oedev")
    with pytest.raises(AccountNotFound):
        s.get("oedev")
    assert s.active() == "oedev"  # state keeps the name even if slot gone (caller handles)


def test_exists_and_touch_last_used(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    s = AccountStore(Paths("token_slayer"))
    assert s.exists("ghost") is False
    s.add(_acc("oedev"))
    assert s.exists("oedev") is True
    assert s.get("oedev").last_used is None
    s.touch_last_used("oedev")
    updated = s.get("oedev")
    assert updated.last_used is not None
    assert updated.last_used > 0


def test_active_returns_none_when_unset(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    s = AccountStore(Paths("token_slayer"))
    assert s.active() is None


def test_remove_missing_slot_raises(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    s = AccountStore(Paths("token_slayer"))
    with pytest.raises(AccountNotFound):
        s.remove("ghost")


def test_writes_are_atomic_and_leave_no_tmp_debris(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    s = AccountStore(p)
    s.add(_acc("oedev"))
    assert list(p.accounts_dir.glob("*.tmp")) == []
    s.touch_last_used("oedev")
    assert list(p.accounts_dir.glob("*.tmp")) == []
    assert s.get("oedev").token == "sk-ant-oat01-oedev"  # slot still round-trips
