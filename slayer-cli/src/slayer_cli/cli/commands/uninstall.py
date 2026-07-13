"""`uninstall` subcommand: reversible teardown of the switcher."""
from __future__ import annotations

import click

from slayer_cli import teardown

__all__ = ["command"]


@click.command(name="uninstall")
@click.option("--yes", "-y", is_flag=True, help="Skip the confirmation prompt.")
@click.option(
    "--keep-accounts",
    is_flag=True,
    help="Preserve stored account slots and switch state (usage cache is still cleared).",
)
@click.pass_obj
def command(services, yes: bool, keep_accounts: bool) -> None:
    """Remove the switcher's local footprint and restore your original Claude login.

    Restores `~/.claude/.credentials.json` from its pristine pre-slayer
    `.slayer-bak` backup (if one exists), then removes the venv, the
    `token-slayer`/`slayer` shim, and the attribution file. Unless
    --keep-accounts is given, the stored account slots, switch state, swap
    history, and usage cache are removed too.

    Does NOT touch the token-slayer event-tracking hook footprint
    (send-hook.sh, hook token, detector-config, custom.sh, shell-rc block) —
    that is a separate manual step.
    """
    paths = services.paths
    if not yes and not _confirm(paths, keep_accounts):
        click.echo("Aborted.")
        return

    summary = teardown.uninstall(paths, keep_accounts=keep_accounts)

    if summary.credential_restored:
        click.echo("Your original Claude login was restored.")
    else:
        click.echo("No credential backup found; your current Claude login was left as-is.")

    for item in summary.removed:
        click.echo(f"Removed: {item}")
    for note in summary.notes:
        click.echo(note)

    click.echo("token-slayer switcher uninstalled.")


def _confirm(paths, keep_accounts: bool) -> bool:
    """Show exactly what will be removed/restored and ask the user to proceed.

    :param paths: Resolved OS paths for the active namespace.
    :param keep_accounts: Whether account slots/state will be preserved.
    :return: True if the user confirmed, False if they declined.
    """
    lines = [
        "This will:",
        "  - remove the venv, the 'token-slayer' shim, and the 'slayer' symlink",
        "  - remove the attribution file (active.json)",
    ]
    if keep_accounts:
        lines.append("  - keep your stored accounts and switch state (usage cache still removed)")
    else:
        lines.append("  - remove your stored accounts, switch state, swap history, and usage cache")
    if paths.claude_credentials_backup.is_file():
        lines.append("  - restore your original Claude login from backup")
    else:
        lines.append("  - leave your current Claude login as-is (no backup found)")
    click.echo("\n".join(lines))
    return click.confirm("Proceed?")
