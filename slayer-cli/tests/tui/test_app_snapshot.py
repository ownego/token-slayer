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
    # Freeze BOTH wall-clock sources so the baseline is time-independent:
    #  - the Header's live clock (textual's datetime.now())
    #  - reset_countdown's time.time() (renders the "in <N>d <N>h" column)
    # Without the second freeze the countdown drifts hour-by-hour and the
    # committed SVG breaks on other days/machines.
    monkeypatch.setattr("textual.widgets._header.datetime", _FrozenDateTime)
    monkeypatch.setattr("slayer_cli.tui.format.time.time", lambda: 1_700_000_000.0)
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
        # Wait for the usage-fetch worker threads to complete AND their
        # call_from_thread redraws to land — deterministic, not a fixed sleep.
        await pilot.app.workers.wait_for_complete()
        await pilot.pause()

    assert snap_compare(app, run_before=run_before, terminal_size=(100, 30))
