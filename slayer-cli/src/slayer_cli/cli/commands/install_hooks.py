"""`install-hooks` subcommand: v1-minimal — shells the same served install
script as `update` rather than porting the bash installer's hook-merge."""
from __future__ import annotations

import click

from slayer_cli.cli.commands.update import run_install_script

__all__ = ["command"]


@click.command(name="install-hooks")
def command() -> None:
    """Install/refresh the Claude Code hooks via the served install script."""
    run_install_script()
