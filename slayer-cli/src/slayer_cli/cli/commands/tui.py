"""`tui` subcommand and the shared TUI launch entrypoint.

`main`'s group callback calls `launch(paths)` directly when invoked with no
subcommand; the `tui` command below calls the same function explicitly."""
from __future__ import annotations

import click

from slayer_cli.platform.paths import Paths

__all__ = ["command", "launch"]


def launch(paths: Paths) -> None:
    """Launch the interactive TUI.

    :param paths: Resolved OS paths for this namespace.
    :return: None
    """
    from slayer_cli.tui.app import SlayerApp

    SlayerApp(paths).run()


@click.command(name="tui")
@click.pass_obj
def command(services) -> None:
    """Launch the interactive TUI explicitly."""
    launch(services.paths)
