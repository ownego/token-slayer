"""Tests for usage.poller.refresh_all_usage — multi-account usage polling.

The active account is polled with the live grant; non-active accounts are
safely refreshed (their expired access token rotated via the stored refresh
token) then polled; a per-account TTL skips still-fresh non-active entries.
"""
from __future__ import annotations

from slayer_cli.accounts.store import AccountStore
from slayer_cli.credstore.refresh import RefreshError
from slayer_cli.models.account import Account
from slayer_cli.models.usage_windows import AccountUsage, Window
from slayer_cli.platform.paths import Paths
from slayer_cli.usage import cache as usage_cache
from slayer_cli.usage import poller


def _store(tmp_path, monkeypatch):
    """Return a fresh AccountStore rooted at an isolated HOME."""
    monkeypatch.setenv("HOME", str(tmp_path))
    return AccountStore(Paths("token_slayer"))


def _acct(name, token, *, refresh="ort01-x", expires=9_999_999_999_999):
    """Build an Account slot with a refresh token and (far-future) expiry."""
    return Account(name=name, email=f"{name}@x.com", org_uuid=f"o-{name}", uuid=f"u-{name}",
                   plan=None, token=token, refresh_token=refresh, expires_at=expires, added_at=1)


def _fetch_recorder(monkeypatch, polled_at=100):
    """Monkeypatch fetch_usage to record every token it is called with."""
    seen = []
    monkeypatch.setattr("slayer_cli.usage.oauth.fetch_usage",
                        lambda token, **kw: seen.append(token) or AccountUsage(polled_at=polled_at))
    return seen


def test_active_polled_with_live_grant_not_slot(tmp_path, monkeypatch):
    """The active account is polled with Claude's live token, never its stale slot copy."""
    store = _store(tmp_path, monkeypatch)
    store.add(_acct("a", "sk-ant-oat01-STALE"))
    store.set_active("a")
    monkeypatch.setattr("slayer_cli.credstore.read_active_full",
                        lambda paths: {"accessToken": "sk-ant-oat01-LIVE"})
    seen = _fetch_recorder(monkeypatch)
    poller.refresh_all_usage(store, Paths("token_slayer"), now=100)
    assert seen == ["sk-ant-oat01-LIVE"]


def test_non_active_expired_is_refreshed_polled_and_persisted(tmp_path, monkeypatch):
    """A non-active account with an expired token is refreshed (rotated grant
    persisted back to the slot) then polled with the fresh token."""
    store = _store(tmp_path, monkeypatch)
    store.add(_acct("a", "sk-ant-oat01-A"))
    store.set_active("a")
    store.add(_acct("b", "sk-ant-oat01-B-STALE", refresh="ort01-b", expires=1))
    monkeypatch.setattr("slayer_cli.credstore.read_active_full", lambda paths: {"accessToken": "sk-ant-oat01-A"})
    monkeypatch.setattr("slayer_cli.credstore.refresh.is_expired",
                        lambda block, **kw: block.get("accessToken", "").endswith("STALE"))
    monkeypatch.setattr("slayer_cli.credstore.refresh.refresh_grant",
                        lambda block, **kw: {"accessToken": "sk-ant-oat01-B-FRESH",
                                             "refreshToken": "ort01-b2", "expiresAt": 9_999_999_999_999})
    seen = _fetch_recorder(monkeypatch)
    poller.refresh_all_usage(store, Paths("token_slayer"), now=100)
    assert "sk-ant-oat01-B-FRESH" in seen
    assert store.get("b").token == "sk-ant-oat01-B-FRESH"
    assert store.get("b").refresh_token == "ort01-b2"


def test_non_active_fresh_cache_is_skipped(tmp_path, monkeypatch):
    """A non-active account whose cached usage is younger than the TTL is not re-polled."""
    store = _store(tmp_path, monkeypatch)
    store.add(_acct("a", "sk-ant-oat01-A"))
    store.set_active("a")
    store.add(_acct("b", "sk-ant-oat01-B"))
    usage_cache.save_cache(Paths("token_slayer"),
                           {usage_cache.cache_key(store.get("b")):
                            AccountUsage(five_hour=Window(utilization=10.0), polled_at=100)})
    monkeypatch.setattr("slayer_cli.credstore.read_active_full", lambda paths: {"accessToken": "sk-ant-oat01-A"})
    seen = _fetch_recorder(monkeypatch, polled_at=120)
    poller.refresh_all_usage(store, Paths("token_slayer"), now=120)  # now-100=20 < 300 TTL
    assert "sk-ant-oat01-B" not in seen
    assert "sk-ant-oat01-A" in seen  # active always polled


def test_active_always_polled_even_when_cache_fresh(tmp_path, monkeypatch):
    """The active account is polled every call regardless of TTL (it's the account being gated)."""
    store = _store(tmp_path, monkeypatch)
    store.add(_acct("a", "sk-ant-oat01-A"))
    store.set_active("a")
    usage_cache.save_cache(Paths("token_slayer"),
                           {usage_cache.cache_key(store.get("a")):
                            AccountUsage(five_hour=Window(utilization=10.0), polled_at=118)})
    monkeypatch.setattr("slayer_cli.credstore.read_active_full", lambda paths: {"accessToken": "sk-ant-oat01-LIVE"})
    seen = _fetch_recorder(monkeypatch, polled_at=120)
    poller.refresh_all_usage(store, Paths("token_slayer"), now=120)
    assert seen == ["sk-ant-oat01-LIVE"]


def test_non_active_refresh_failure_falls_back_to_stale_token(tmp_path, monkeypatch):
    """When a non-active refresh fails (refresh token also dead), poll with the
    stale token (a 401 just yields empty usage) — never crash, never rotate."""
    store = _store(tmp_path, monkeypatch)
    store.add(_acct("a", "sk-ant-oat01-A"))
    store.set_active("a")
    store.add(_acct("b", "sk-ant-oat01-B-STALE", refresh="ort01-dead", expires=1))
    monkeypatch.setattr("slayer_cli.credstore.read_active_full", lambda paths: {"accessToken": "sk-ant-oat01-A"})
    monkeypatch.setattr("slayer_cli.credstore.refresh.is_expired", lambda block, **kw: True)

    def boom(block, **kw):
        raise RefreshError("refresh token dead")

    monkeypatch.setattr("slayer_cli.credstore.refresh.refresh_grant", boom)
    seen = _fetch_recorder(monkeypatch)
    poller.refresh_all_usage(store, Paths("token_slayer"), now=100)  # must not raise
    assert "sk-ant-oat01-B-STALE" in seen
    assert store.get("b").token == "sk-ant-oat01-B-STALE"  # unchanged
