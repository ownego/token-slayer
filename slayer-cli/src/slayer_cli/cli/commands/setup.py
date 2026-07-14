"""`setup` subcommand: pull admin-provisioned accounts from the token-slayer
server and configure Claude Code. NEVER prints the token."""
from __future__ import annotations

import click

from slayer_cli.accounts import provisioned
from slayer_cli.errors import SlayerError

__all__ = ["command"]


@click.command(name="setup")
@click.pass_obj
def command(services) -> None:
    """Fetch accounts an admin provisioned for you and configure Claude Code."""
    paths = services.paths
    token_file = paths.config_dir / "token"
    if not token_file.is_file():
        raise click.ClickException("No hook token found — install token-slayer first.")
    hook_token = token_file.read_text().strip()
    try:
        names = provisioned.pull_and_setup(paths, hook_token)
    except SlayerError as exc:
        raise click.ClickException(str(exc)) from exc
    if not names:
        click.echo("No provisioned accounts available. Ask your admin to provision one.")
        return
    click.echo(f"Set up {len(names)} account(s): {', '.join(names)}")
    click.echo("Claude Code is ready. (Your token is never shown.)")
