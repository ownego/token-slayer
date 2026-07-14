"""`install-hooks` subcommand: shells the same served install script as
`update` for the attribution hook footprint, then upserts the switcher's own
coordination hooks (SessionStart/Stop/failure/prompt-submit) into
settings.json via `hookinstall` (by-signature — coexists with the
attribution hook and any other tool's hooks)."""
from __future__ import annotations

import click

from slayer_cli.autoswitch import hookinstall
from slayer_cli.cli.commands.update import run_install_script
from slayer_cli.platform.paths import Paths

__all__ = ["command"]


@click.command(name="install-hooks", hidden=True)
def command() -> None:
    """Install/refresh the Claude Code hooks via the served install script,
    then install the switcher's own coordination hooks.
    """
    run_install_script()
    hookinstall.install(Paths(Paths.current_ns()))
