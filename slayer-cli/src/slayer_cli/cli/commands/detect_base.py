"""`detect-base` subcommand: register the machine's current Claude login as a
base account slot. Invoked once at install time; idempotent, never prints the
token."""
from __future__ import annotations

import click

from slayer_cli.accounts import base

__all__ = ["command"]


@click.command(name="detect-base")
@click.pass_obj
def command(services) -> None:
    """Register the machine's current Claude login as a base account slot.

    Idempotent: does nothing when no Claude login is active or when the account
    is already tracked.

    :param services: Shared CLI services (paths + account store).
    :return: None
    """
    account, status = base.add_base_account(services.store, services.paths)
    if status == "none":
        click.echo("No active Claude login detected; no base account added.")
    elif status == "exists":
        click.echo(f"Base account already tracked: {account.email or account.name}")
    else:
        click.echo(f"Added base account: {account.email or account.name}")
