"""`run` subcommand: launch `claude` under the auto-switch wrapper."""
from __future__ import annotations

import shutil
import sys

import click

from slayer_cli.autoswitch import wrapper
from slayer_cli.errors import SlayerError

__all__ = ["command"]


@click.command(name="run", context_settings={"ignore_unknown_options": True}, hidden=True)
@click.argument("claude_args", nargs=-1, type=click.UNPROCESSED)
@click.pass_obj
def command(services, claude_args: tuple[str, ...]) -> None:
    """Launch `claude` under the auto-switch wrapper.

    Everything after `--` is passed through to `claude` unchanged, e.g.
    `token-slayer run -- --model opus`.
    """
    claude_bin = shutil.which("claude")
    if claude_bin is None:
        raise click.ClickException("claude not found on PATH")
    try:
        exit_code = wrapper.run(claude_bin, list(claude_args), services)
    except SlayerError as exc:
        raise click.ClickException(str(exc)) from exc
    sys.exit(exit_code)
