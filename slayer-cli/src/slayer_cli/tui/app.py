"""The `token-slayer` Textual dashboard: a single Accounts page. Ported from
ccm's `dashboard.py` `CCMDashboard` (Accounts page only — the Sessions page
and its `h`/`l` tab keys are deferred to a future session-management phase).

No token is ever rendered: the table/detail panel show name/email/org/
utilization/reset only, sourced from `Account` and `UsageSnapshot`."""
from __future__ import annotations

from textual.app import App, ComposeResult
from textual.binding import Binding
from textual.containers import Horizontal
from textual.widgets import DataTable, Footer, Header

from slayer_cli.accounts.store import AccountStore
from slayer_cli.accounts.switch import switch_to
from slayer_cli.config import store as config_store
from slayer_cli.errors import SlayerError
from slayer_cli.models.account import Account
from slayer_cli.models.usage import UsageSnapshot
from slayer_cli.platform.paths import Paths
from slayer_cli.tui.widgets.account_table import AccountTable
from slayer_cli.tui.widgets.detail_panel import DetailPanel
from slayer_cli.usage.service import UsageService

__all__ = ["SlayerApp"]

REFRESH_INTERVAL_SECONDS = 30
"""Periodic background usage re-fetch cadence, ccm's `REFRESH_INTERVAL`."""


class SlayerApp(App):
    """The Accounts-page dashboard: vim-style navigation, live usage bars,
    and account switching. Layout: `Header(clock)` + `AccountTable` +
    `DetailPanel` + `Footer`.
    """

    TITLE = "token-slayer"
    CSS = """
    Screen { layout: vertical; }
    #main { layout: horizontal; height: 1fr; }
    AccountTable { width: 2fr; height: 100%; }
    DetailPanel {
        width: 3fr;
        height: 100%;
        border-left: solid $primary-darken-2;
        padding: 1 2;
        overflow-y: auto;
    }
    """
    BINDINGS = [
        ("j", "cursor_down", "↓"),
        ("k", "cursor_up", "↑"),
        ("g", "cursor_top", "Top"),
        ("G", "cursor_bottom", "Bottom"),
        ("s", "switch_selected", "Switch"),
        ("r", "refresh", "Refresh"),
        # Auto-switch strategy control is hidden pending release (key still
        # works for verification; not shown in the footer).
        Binding("c", "cycle_strategy", "Strategy", show=False),
        ("q", "quit", "Quit"),
    ]

    def __init__(self, paths: Paths) -> None:
        """
        :param paths: Resolved OS paths for this namespace.
        """
        super().__init__()
        self._paths = paths
        self._store = AccountStore(paths)
        self._usage = UsageService(paths)
        self._accounts: list[Account] = self._store.list()
        self._active: str | None = self._store.active()
        self._snapshots: dict[str, UsageSnapshot | None] = {}

    def compose(self) -> ComposeResult:
        """Build the widget tree: header, account table + detail panel, footer.

        :return: The composed widgets.
        """
        yield Header(show_clock=True)
        with Horizontal(id="main"):
            yield AccountTable(id="table")
            yield DetailPanel("Select an account", id="detail")
        yield Footer()

    def on_mount(self) -> None:
        """Populate the table, fetch usage for every account, and schedule
        periodic refresh.

        :return: None
        """
        self._rebuild_table()
        self._start_refresh()
        self.set_interval(REFRESH_INTERVAL_SECONDS, self._start_refresh)

    # -- rendering ------------------------------------------------------

    def _rebuild_table(self) -> None:
        """Redraw the account table and sync the detail panel to the
        cursor's row (the active slot on first draw).

        :return: None
        """
        table = self.query_one("#table", AccountTable)
        table.set_rows(self._accounts, self._active, self._snapshots)
        if not self._accounts:
            return
        active_index = next(
            (i for i, a in enumerate(self._accounts) if a.name == self._active), 0
        )
        table.move_cursor(row=active_index)
        self._update_detail(self._accounts[active_index].name)

    def _update_detail(self, name: str) -> None:
        """Show `name`'s usage in the detail panel.

        :param name: Account slot name.
        :return: None
        """
        account = next((a for a in self._accounts if a.name == name), None)
        if account is None:
            return
        panel = self.query_one("#detail", DetailPanel)
        panel.show(account, self._snapshots.get(name), account.name == self._active)

    def on_data_table_row_highlighted(self, event: DataTable.RowHighlighted) -> None:
        """Sync the detail panel whenever the table cursor moves.

        :param event: Textual's row-highlight event, carrying the row key.
        :return: None
        """
        key = event.row_key
        if key is not None and key.value:
            self._update_detail(key.value)

    # -- cursor actions (`j`/`k`/`g`/`G`) --------------------------------

    def action_cursor_down(self) -> None:
        """Move the table cursor down one row.

        :return: None
        """
        self.query_one("#table", AccountTable).action_cursor_down()

    def action_cursor_up(self) -> None:
        """Move the table cursor up one row.

        :return: None
        """
        self.query_one("#table", AccountTable).action_cursor_up()

    def action_cursor_top(self) -> None:
        """Jump the table cursor to the first row.

        :return: None
        """
        self.query_one("#table", AccountTable).move_cursor(row=0)

    def action_cursor_bottom(self) -> None:
        """Jump the table cursor to the last row.

        :return: None
        """
        table = self.query_one("#table", AccountTable)
        table.move_cursor(row=max(0, table.row_count - 1))

    # -- switch (`s`) -----------------------------------------------------

    def action_switch_selected(self) -> None:
        """Switch to the account slot under the cursor via
        `accounts.switch.switch_to`.

        :return: None
        """
        table = self.query_one("#table", AccountTable)
        row = table.cursor_row
        if row is None or row >= len(self._accounts):
            return
        target = self._accounts[row]
        if target.name == self._active:
            self.notify(f"Already on {target.name}", timeout=2)
            return
        try:
            switch_to(self._store, target.name, paths=self._paths)
        except SlayerError as exc:
            self.notify(f"Switch failed: {exc}", severity="error")
            return
        self._active = target.name
        self._rebuild_table()
        self.notify(f"Switched to {target.name}", timeout=2)

    # -- refresh (`r`) ------------------------------------------------------

    def action_refresh(self) -> None:
        """Clear the in-memory usage snapshots and force a LIVE re-fetch of
        every account (bypasses the usage cache TTL — a manual refresh should
        fetch fresh data, not re-serve the cached snapshot).

        :return: None
        """
        self._snapshots = {}
        self._rebuild_table()
        self._start_refresh(force=True)

    def _start_refresh(self, force: bool = False) -> None:
        """Kick off one concurrent, thread-backed usage fetch per account
        so the UI never blocks on network I/O.

        :param force: When True, each fetch bypasses the cache TTL and probes
            live (used by the manual `r` refresh; the periodic refresh leaves
            it False so it serves cached snapshots between live probes).
        :return: None
        """
        for account in self._accounts:
            self.run_worker(lambda acc=account: self._fetch_one(acc, force), thread=True, exclusive=False)

    def _fetch_one(self, account: Account, force: bool = False) -> None:
        """Fetch `account`'s usage (blocking; runs in a worker thread) and
        hand the result back to the UI thread.

        :param account: Account slot to fetch quota for.
        :param force: When True, bypass the cache TTL and probe live.
        :return: None
        """
        snapshot = self._usage.get(account, force=force)
        self.call_from_thread(self._on_usage_ready, account.name, snapshot)

    def _on_usage_ready(self, name: str, snapshot: UsageSnapshot) -> None:
        """Store a fetched snapshot and redraw (runs on the UI thread).

        :param name: Account slot name the snapshot belongs to.
        :param snapshot: The fetched usage snapshot.
        :return: None
        """
        self._snapshots[name] = snapshot
        self._rebuild_table()

    # -- strategy cycling (`c`) ------------------------------------------

    def action_cycle_strategy(self) -> None:
        """Cycle `strategy.kind` manual->balanced->drain->manual via
        `config.store`, persist it, and refresh the visible label.

        :return: None
        """
        cfg = config_store.load(self._paths)
        next_kind = config_store.next_strategy_kind(cfg.strategy.kind)
        cfg = config_store.set_value(cfg, "strategy.kind", next_kind)
        config_store.save(self._paths, cfg)
        self._refresh_strategy_label()
        self.notify(f"Strategy: {next_kind}", timeout=2)

    def _refresh_strategy_label(self) -> None:
        """Set the Header's subtitle to show the current `strategy.kind`.

        :return: None
        """
        cfg = config_store.load(self._paths)
        self.sub_title = f"strategy: {cfg.strategy.kind}"
