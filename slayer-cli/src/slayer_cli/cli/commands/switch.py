"""`switch` subcommand: make a stored slot the active Claude account."""
from __future__ import annotations

import click

from slayer_cli.accounts.switch import switch_to
from slayer_cli.errors import SlayerError

__all__ = ["command"]


@click.command(name="switch")
@click.argument("name")
@click.pass_obj
def command(services, name: str) -> None:
    """Switch the active Claude account to the slot NAME."""
    try:
        switch_to(services.store, name, paths=services.paths)
    except SlayerError as exc:
        raise click.ClickException(str(exc)) from exc
    click.echo(f"Switched to {name}")
