import pytest

from slayer_cli.accounts import switch as switch_mod
from slayer_cli.accounts.store import AccountStore
from slayer_cli.models.account import Account
from slayer_cli.models.usage import UsageSnapshot
from slayer_cli.platform.paths import Paths
from slayer_cli.usage.service import UsageService


def _account(name: str, org_uuid: str) -> Account:
    return Account(
        name=name, email=f"{name}@example.com", org_uuid=org_uuid, uuid=None,
        plan=None, token="sk-ant-oat01-TESTTOKEN", added_at=1, last_used=None,
    )


def _seed_two_accounts(tmp_path, monkeypatch) -> AccountStore:
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    paths = Paths("token_slayer")
    store = AccountStore(paths)
    store.add(_account("oedev", "o1"))
    store.add(_account("clone", "o2"))
    store.set_active("oedev")
    return paths, store


def _stub_switch_credential_writes(monkeypatch) -> None:
    """No-op the global credential/`.claude.json` side effects `switch_to`
    performs, so the pilot test only exercises slot-store bookkeeping."""
    monkeypatch.setattr(switch_mod.credstore, "write_active_token", lambda *a, **k: None)
    monkeypatch.setattr(switch_mod.credstore.claude_json, "patch_oauth_account", lambda *a, **k: None)


def _stub_usage_service(monkeypatch) -> None:
    """Replace `UsageService.get` with a fixed snapshot so no network call is made."""
    monkeypatch.setattr(
        UsageService, "get",
        lambda self, account, force=False: UsageSnapshot(
            s5h_util=0.3, s5h_status="allowed", s5h_reset=9_999_999_999,
            s7d_util=0.1, s7d_reset=9_999_999_999,
        ),
    )


@pytest.mark.anyio
async def test_switch_key_switches_selected(tmp_path, monkeypatch):
    # AccountStore.list() sorts by name ("clone" < "oedev"), so the table's
    # initial cursor sits on the active row (index 1, "oedev"); "k" moves it
    # up to "clone" before "s" switches to the row under the cursor.
    paths, store = _seed_two_accounts(tmp_path, monkeypatch)
    _stub_switch_credential_writes(monkeypatch)
    _stub_usage_service(monkeypatch)

    from slayer_cli.tui.app import SlayerApp

    app = SlayerApp(paths)
    async with app.run_test() as pilot:
        await pilot.press("k", "s")

    assert store.active() in {"oedev", "clone"}
    assert store.active() == "clone"


@pytest.mark.anyio
async def test_cycle_strategy_key_updates_config_and_label(tmp_path, monkeypatch):
    paths, _store = _seed_two_accounts(tmp_path, monkeypatch)
    _stub_switch_credential_writes(monkeypatch)
    _stub_usage_service(monkeypatch)

    from slayer_cli.config import store as config_store
    from slayer_cli.tui.app import SlayerApp

    # The strategy control is hidden (auto-switch not released): no subtitle on
    # mount, and the `c` binding is not shown in the footer — but the key still
    # works, so cycling persists config and shows transient feedback.
    app = SlayerApp(paths)
    async with app.run_test() as pilot:
        await pilot.press("c")
        assert "balanced" in app.sub_title
        await pilot.press("c")
        assert "drain" in app.sub_title

    assert config_store.load(paths).strategy.kind == "drain"


@pytest.mark.anyio
async def test_quit_key_exits(tmp_path, monkeypatch):
    paths, _store = _seed_two_accounts(tmp_path, monkeypatch)
    _stub_switch_credential_writes(monkeypatch)
    _stub_usage_service(monkeypatch)

    from slayer_cli.tui.app import SlayerApp

    app = SlayerApp(paths)
    async with app.run_test() as pilot:
        await pilot.press("q")
        assert not app.is_running


@pytest.mark.anyio
async def test_refresh_key_forces_live_fetch(tmp_path, monkeypatch):
    """Pressing `r` fetches usage with force=True (bypassing the cache TTL);
    the initial/periodic fetch uses force=False."""
    paths, _store = _seed_two_accounts(tmp_path, monkeypatch)
    _stub_switch_credential_writes(monkeypatch)

    forces = []
    monkeypatch.setattr(
        UsageService, "get",
        lambda self, account, force=False: forces.append(force) or UsageSnapshot(
            five_hour_utilization=0.0, seven_day_utilization=0.0, status="ok"),
    )

    from slayer_cli.tui.app import SlayerApp
    app = SlayerApp(paths)
    async with app.run_test() as pilot:
        forces.clear()          # drop the on-mount (force=False) fetches
        await pilot.press("r")
        await pilot.pause()

    assert forces, "pressing r should trigger fetches"
    assert all(f is True for f in forces)  # every fetch from `r` is forced
