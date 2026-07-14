"""`uninstall-hooks` subcommand: strips only the switcher's own
coordination hooks from settings.json (by-signature), leaving any other
tool's hooks — and the attribution hook footprint — untouched."""
from __future__ import annotations

import click

from slayer_cli.autoswitch import hookinstall
from slayer_cli.platform.paths import Paths

__all__ = ["command"]


@click.command(name="uninstall-hooks", hidden=True)
def command() -> None:
    """Remove the switcher's coordination hooks from settings.json.
    """
    hookinstall.uninstall(Paths(Paths.current_ns()))
    click.echo("token-slayer coordination hooks removed.")
