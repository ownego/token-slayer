"""Click CLI entrypoint (`token-slayer` / `slayer`). Builds the `Services`
bundle shared by every subcommand and, with no subcommand given, launches
the TUI. This module and `cli/commands/` stay thin — parse input, call a
service, render output; all logic lives in `accounts/`, `usage/`, etc."""
from __future__ import annotations

from dataclasses import dataclass

import click

from slayer_cli.accounts.store import AccountStore
from slayer_cli.cli.commands import add, alias, config, current, detect_base, force_switch, hook, install_hooks, remove, run, setup, sessions, status, switch, tui, uninstall, uninstall_hooks, update
from slayer_cli.cli.commands import list as list_cmd
from slayer_cli.platform.paths import Paths

__all__ = ["main", "Services"]


@dataclass
class Services:
    """Shared services threaded through `ctx.obj` to every subcommand.

    :var paths: Resolved OS paths for the active namespace.
    :var store: Account slot store for the active namespace.
    """

    paths: Paths
    store: AccountStore


@click.group(invoke_without_command=True)
@click.pass_context
def main(ctx: click.Context) -> None:
    """token-slayer: manage and switch Claude Max account slots.

    With no subcommand, launches the interactive TUI.
    """
    paths = Paths(Paths.current_ns())
    ctx.obj = Services(paths=paths, store=AccountStore(paths))
    if ctx.invoked_subcommand is None:
        from slayer_cli.cli.commands import tui

        tui.launch(ctx.obj.paths)


main.add_command(add.command, name="add")
main.add_command(alias.command, name="alias")
main.add_command(config.command, name="config")
main.add_command(list_cmd.command, name="list")
main.add_command(list_cmd.command, name="ls")
main.add_command(remove.command, name="remove")
main.add_command(switch.command, name="switch")
main.add_command(force_switch.command, name="force-switch")
main.add_command(current.command, name="current")
main.add_command(status.command, name="status")
main.add_command(sessions.command, name="sessions")
main.add_command(update.command, name="update")
main.add_command(install_hooks.command, name="install-hooks")
main.add_command(uninstall_hooks.command, name="uninstall-hooks")
main.add_command(hook.command, name="hook")
main.add_command(setup.command, name="setup")
main.add_command(detect_base.command, name="detect-base")
main.add_command(uninstall.command, name="uninstall")
main.add_command(run.command, name="run")
main.add_command(tui.command, name="tui")
