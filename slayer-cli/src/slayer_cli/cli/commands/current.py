"""`current` subcommand: show the active account slot's name and email/org.
NEVER prints the token."""
from __future__ import annotations

import click

__all__ = ["command"]


@click.command(name="current")
@click.pass_obj
def command(services) -> None:
    """Print the active account slot's name and email/org, or "none"."""
    active = services.store.active()
    if active is None or not services.store.exists(active):
        click.echo("Active account: none")
        return
    acc = services.store.get(active)
    detail = acc.email or acc.org_uuid or "unknown"
    click.echo(f"Active account: {acc.name} ({detail})")
