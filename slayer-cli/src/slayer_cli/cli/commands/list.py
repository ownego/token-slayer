"""`list` (aliased as `ls`) subcommand: print known account slots as a
table. NEVER prints the token — only name/email/org/active-marker."""
from __future__ import annotations

import click

from slayer_cli.models.account import Account

__all__ = ["command"]


@click.command(name="list")
@click.pass_obj
def command(services) -> None:
    """List all account slots, marking the active one."""
    accounts = services.store.list()
    if not accounts:
        click.echo("No account slots yet. Add one with `slayer add <name>`.")
        return
    active = services.store.active()
    for line in _render_table(accounts, active):
        click.echo(line)


def _render_table(accounts: list[Account], active: str | None) -> list[str]:
    """Render `accounts` as aligned table rows, marking `active` with `*`.

    :param accounts: Account slots to render.
    :param active: Name of the active slot, or None.
    :return: One string per output line (header first).
    """
    headers = ("", "Account", "Email", "Org")
    rows = [
        ("*" if acc.name == active else " ", acc.name, acc.email or "-", acc.org_uuid or "-")
        for acc in accounts
    ]
    widths = [
        max(len(headers[i]), *(len(row[i]) for row in rows))
        for i in range(len(headers))
    ]
    lines = [_format_row(headers, widths)]
    lines += [_format_row(row, widths) for row in rows]
    return lines


def _format_row(cells: tuple[str, ...], widths: list[int]) -> str:
    """Left-pad-join `cells` to `widths`, separated by two spaces.

    :param cells: Row values.
    :param widths: Column widths, same length as `cells`.
    :return: The formatted row.
    """
    return "  ".join(cell.ljust(width) for cell, width in zip(cells, widths))
