"""`force-switch` subcommand: switch even if the outgoing slot is broken
(skips rotation capture). Recovery path."""
from __future__ import annotations

import click

from slayer_cli.accounts.switch import switch_to
from slayer_cli.errors import SlayerError

__all__ = ["command"]


@click.command(name="force-switch")
@click.argument("target")
@click.pass_obj
def command(services, target: str) -> None:
    """Force-switch to TARGET (slot/alias/email), bypassing rotation capture."""
    try:
        account = services.store.resolve(target)
        switch_to(services.store, account.name, paths=services.paths, force=True)
        click.echo(f"Switched to {account.name} (forced).")
    except SlayerError as exc:
        raise click.ClickException(str(exc)) from exc
