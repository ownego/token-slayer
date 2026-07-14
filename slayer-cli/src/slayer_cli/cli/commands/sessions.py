"""`sessions` subcommand: print running wrapper processes and their states."""
from __future__ import annotations

import click

from slayer_cli.autoswitch import registry

__all__ = ["command"]


@click.command(name="sessions", hidden=True)
@click.pass_obj
def command(services) -> None:
    """List running wrapper processes with their state, account, and working directory."""
    entries = registry.list(services.paths)
    if not entries:
        click.echo("No running sessions.")
        return
    for line in _render_table(entries):
        click.echo(line)


def _render_table(entries: list[registry.Entry]) -> list[str]:
    """Render `entries` as aligned table rows.

    :param entries: Session entries to render.
    :return: One string per output line (header first).
    """
    headers = ("PID", "State", "Account", "CWD")
    rows = [
        (str(entry.pid), entry.state, entry.account or "-", entry.cwd)
        for entry in entries
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
