"""Tests for `usage.stop_refresh.refresh_active_on_stop` — the unconditional
(non-TS_WRAPPED) Stop-hook refresh that keeps the TUI's usage cache warm
without requiring auto-switch."""
from __future__ import annotations

import httpx

from slayer_cli.accounts.store import AccountStore
from slayer_cli.models.account import Account
from slayer_cli.platform.paths import Paths
from slayer_cli.usage import stop_refresh
from slayer_cli.usage.service import UsageService


def _paths(tmp_path, monkeypatch) -> Paths:
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    return Paths("token_slayer")


def _account(name: str = "oedev") -> Account:
    return Account(name=name, email=f"{name}@x.com", org_uuid="o1", uuid=None, plan=None,
                    token="sk-ant-oat01-TESTTOKEN", added_at=1)


def test_refresh_active_on_stop_warms_the_usage_cache(tmp_path, monkeypatch):
    """A live probe result for the active account lands in the TUI's cache,
    so a subsequent non-force `UsageService.get` serves it without a new probe."""
    paths = _paths(tmp_path, monkeypatch)
    store = AccountStore(paths)
    account = _account()
    store.add(account)
    store.set_active(account.name)

    def handler(request):
        return httpx.Response(200, headers={"anthropic-ratelimit-unified-5h-utilization": "0.75"}, json={})
    monkeypatch.setattr(
        "slayer_cli.usage.fetcher.make_client",
        lambda: httpx.Client(transport=httpx.MockTransport(handler)),
    )

    stop_refresh.refresh_active_on_stop(paths)

    cached = UsageService(paths).get(account, force=False)
    assert cached.s5h_util == 0.75


def test_refresh_active_on_stop_is_noop_without_an_active_account(tmp_path, monkeypatch):
    """No active slot (fresh install, or a dangling pointer) -> silent no-op."""
    paths = _paths(tmp_path, monkeypatch)

    stop_refresh.refresh_active_on_stop(paths)  # must not raise


def test_refresh_active_on_stop_is_noop_for_a_dangling_active_pointer(tmp_path, monkeypatch):
    """`state.json` naming a slot whose file no longer exists must not raise."""
    paths = _paths(tmp_path, monkeypatch)
    AccountStore(paths).set_active("ghost")

    stop_refresh.refresh_active_on_stop(paths)  # must not raise


def test_refresh_active_on_stop_swallows_errors_to_stderr(tmp_path, monkeypatch, capsys):
    """A failure while refreshing must never propagate — it must never break
    the user's Claude Code turn."""
    paths = _paths(tmp_path, monkeypatch)
    store = AccountStore(paths)
    account = _account()
    store.add(account)
    store.set_active(account.name)

    def boom(self, account, force=False):
        raise RuntimeError("boom")
    monkeypatch.setattr(UsageService, "get", boom)

    stop_refresh.refresh_active_on_stop(paths)  # must not raise

    assert "boom" in capsys.readouterr().err
