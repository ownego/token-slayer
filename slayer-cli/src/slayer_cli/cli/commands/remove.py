"""`remove` subcommand: delete an account slot."""
from __future__ import annotations

import click

from slayer_cli.errors import SlayerError

__all__ = ["command"]


@click.command(name="remove")
@click.argument("name")
@click.pass_obj
def command(services, name: str) -> None:
    """Remove the account slot NAME."""
    try:
        services.store.remove(name)
    except SlayerError as exc:
        raise click.ClickException(str(exc)) from exc
    click.echo(f"Removed account slot '{name}'")
