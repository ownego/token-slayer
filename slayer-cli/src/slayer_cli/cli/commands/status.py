"""`status` subcommand: version, namespace, active account, and login state
— reproduces the old bash CLI's `status` output. NEVER prints the token."""
from __future__ import annotations

import click

from slayer_cli import credstore
from slayer_cli.version import __version__

__all__ = ["command"]


@click.command(name="status")
@click.pass_obj
def command(services) -> None:
    """Print version, namespace, active account, and whether a credential is active."""
    click.echo(f"slayer-cli {__version__}")
    click.echo(f"namespace: {services.paths.ns}")
    click.echo(f"active account: {services.store.active() or 'none'}")
    logged_in = credstore.read_active_token(services.paths) is not None
    click.echo(f"logged in: {'yes' if logged_in else 'no'}")
