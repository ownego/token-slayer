"""`alias` subcommand: set or clear an account's friendly alias."""
from __future__ import annotations

import click

from slayer_cli.errors import SlayerError

__all__ = ["command"]


@click.command(name="alias")
@click.argument("target")
@click.argument("name", required=False)
@click.pass_obj
def command(services, target: str, name: str | None) -> None:
    """Set NAME as the alias for account TARGET (slot/alias/email), or clear it when NAME is omitted."""
    try:
        account = services.store.resolve(target)
        services.store.set_alias(account.name, name)
    except SlayerError as exc:
        raise click.ClickException(str(exc)) from exc
    if name:
        click.echo(f"Alias '{name}' set for {account.name}.")
    else:
        click.echo(f"Alias cleared for {account.name}.")
