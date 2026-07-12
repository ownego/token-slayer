"""The Accounts-page detail panel: full-width bars for the selected account.
Ported from ccm's `dashboard.py` `DetailPanel.show`."""
from __future__ import annotations

from textual.widgets import Static

from slayer_cli.models.account import Account
from slayer_cli.models.usage import UsageSnapshot
from slayer_cli.tui import format as fmt
from slayer_cli.usage.parser import pct


class DetailPanel(Static):
    """Right-hand panel: active marker, session/weekly bars, reset info for
    one account. All fill/color/flag/format decisions come from
    `slayer_cli.tui.format` — this widget only lays the strings out."""

    def show(self, account: Account, snapshot: UsageSnapshot | None, is_active: bool) -> None:
        """Render `account`'s usage detail.

        :param account: The account slot to render.
        :param snapshot: Fetched usage snapshot, or `None` while still loading.
        :param is_active: Whether this account is the currently active slot.
        :return: None
        """
        marker = "[bold green]●[/bold green]" if is_active else " "
        badge = "  [bold green][ACTIVE][/bold green]" if is_active else ""
        lines: list[str] = [
            "",
            f"  {marker} [bold]{account.name}[/bold]{badge}",
            "  " + "─" * 38,
        ]

        if snapshot is None:
            lines += ["", "  [dim]Fetching…[/dim]"]
            self.update("\n".join(lines))
            return

        s5h_pct = pct(snapshot.s5h_util)
        s7d_pct = pct(snapshot.s7d_util)
        c5h = fmt.bar_color(s5h_pct)
        c7d = fmt.bar_color(s7d_pct)
        pct5h = f"{s5h_pct}%" if s5h_pct is not None else "—%"
        pct7d = f"{s7d_pct}%" if s7d_pct is not None else "—%"

        lines += [
            "",
            "  [dim]Session (5h)[/dim]",
            f"  [{c5h}]{fmt.full_bar(s5h_pct)}[/{c5h}]  [bold]{pct5h:>4}[/bold]",
        ]
        if fmt.is_limited(snapshot.s5h_status):
            lines.append("  [bold red]⚠  LIMIT HIT[/bold red]")
        lines.append(
            f"  [dim]Resets[/dim]  [cyan]{fmt.reset_clock(snapshot.s5h_reset)}[/cyan]"
            f"  [dim]{fmt.reset_countdown(snapshot.s5h_reset)}[/dim]"
        )

        lines += [
            "",
            "  [dim]Weekly  (7d)[/dim]",
            f"  [{c7d}]{fmt.full_bar(s7d_pct)}[/{c7d}]  [bold]{pct7d:>4}[/bold]",
            f"  [dim]Resets[/dim]  [cyan]{fmt.reset_clock(snapshot.s7d_reset, '%a %H:%M')}[/cyan]"
            f"  [dim]{fmt.reset_countdown(snapshot.s7d_reset)}[/dim]",
            "",
        ]
        self.update("\n".join(lines))
