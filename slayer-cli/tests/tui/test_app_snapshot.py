import datetime as _datetime

from slayer_cli.accounts.store import AccountStore
from slayer_cli.models.account import Account
from slayer_cli.models.usage import UsageSnapshot
from slayer_cli.platform.paths import Paths
from slayer_cli.usage.service import UsageService


class _FrozenDateTime(_datetime.datetime):
    """A `datetime` whose `now()` always returns a fixed instant, so the
    Header's live clock doesn't make the SVG baseline flaky."""

    @classmethod
    def now(cls, tz=None):
        return cls(2026, 1, 1, 12, 0, 0)


def _account(name: str, org_uuid: str) -> Account:
    return Account(
        name=name, email=f"{name}@example.com", org_uuid=org_uuid, uuid=None,
        plan=None, token="sk-ant-oat01-TESTTOKEN", added_at=1, last_used=None,
    )


def test_accounts_page_initial_render(tmp_path, monkeypatch, snap_compare):
    monkeypatch.setattr("textual.widgets._header.datetime", _FrozenDateTime)
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    paths = Paths("token_slayer")
    store = AccountStore(paths)
    store.add(_account("oedev", "o1"))
    store.add(_account("clone", "o2"))
    store.set_active("oedev")

    monkeypatch.setattr(
        UsageService, "get",
        lambda self, account, force=False: UsageSnapshot(
            s5h_util=0.42, s5h_status="allowed", s5h_reset=9_999_999_999,
            s7d_util=0.18, s7d_reset=9_999_999_999,
        ),
    )

    from slayer_cli.tui.app import SlayerApp

    app = SlayerApp(paths)

    async def run_before(pilot):
        # Let the worker threads finish fetching (stubbed, so this is fast)
        # and their call_from_thread redraw land before the screenshot.
        await pilot.pause(0.3)

    assert snap_compare(app, run_before=run_before, terminal_size=(100, 30))
