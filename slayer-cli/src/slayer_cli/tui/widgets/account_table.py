"""The Accounts-page row table: one row per stored account slot. Ported
from ccm's `dashboard.py` `#table` (`_rebuild_accounts_table`)."""
from __future__ import annotations

from textual.widgets import DataTable

from slayer_cli.models.account import Account
from slayer_cli.models.usage import UsageSnapshot
from slayer_cli.tui import format as fmt
from slayer_cli.usage.parser import bar, pct

COLUMNS = ("  Account", "Session 5h", "Weekly 7d", "Resets")
"""Column headers, matching ccm's `#table.add_columns(...)`."""


class AccountTable(DataTable):
    """Row-per-account usage table. All fill/color/flag decisions come from
    `slayer_cli.tui.format` — this widget only lays the strings out."""

    def __init__(self, **kwargs: object) -> None:
        """
        :param kwargs: Forwarded to `DataTable.__init__` (e.g. `id`).
        """
        super().__init__(cursor_type="row", **kwargs)

    def on_mount(self) -> None:
        """Add the fixed column set once mounted.

        :return: None
        """
        self.add_columns(*COLUMNS)

    def set_rows(
        self,
        accounts: list[Account],
        active_name: str | None,
        snapshots: dict[str, UsageSnapshot | None],
    ) -> None:
        """Replace all rows with one per account, in `accounts` order.

        :param accounts: Account slots to render, in display order.
        :param active_name: Name of the currently active slot, or `None`.
        :param snapshots: Per-account fetched usage, keyed by account name;
            a missing/`None` entry renders as still-loading.
        :return: None
        """
        self.clear()
        for account in accounts:
            marker = "● " if account.name == active_name else "  "
            name_col = f"{marker}{account.name}"
            snapshot = snapshots.get(account.name)
            if snapshot is None:
                self.add_row(name_col, "…", "…", "…", key=account.name)
                continue

            s5h_pct = pct(snapshot.s5h_util)
            s7d_pct = pct(snapshot.s7d_util)
            limit_flag = " !" if fmt.is_limited(snapshot.s5h_status) else ""
            s5h_label = "?" if s5h_pct is None else str(s5h_pct)
            s7d_label = "?" if s7d_pct is None else str(s7d_pct)

            self.add_row(
                name_col,
                f"[{fmt.bar_color(s5h_pct)}]{bar(s5h_pct)}[/] {s5h_label}%{limit_flag}",
                f"[{fmt.bar_color(s7d_pct)}]{bar(s7d_pct)}[/] {s7d_label}%",
                fmt.reset_clock(snapshot.s5h_reset),
                key=account.name,
            )
