"""`update` subcommand: re-run the served token-slayer install script.

v1-minimal: does not port the full bash installer, just shells out to the
same `curl | bash` a launcher shim would use. `install-hooks` reuses
`run_install_script` for the same v1 behavior."""
from __future__ import annotations

import os
import subprocess

import click

__all__ = ["command", "run_install_script"]


@click.command(name="update")
def command() -> None:
    """Re-run the served install script from `SLAYER_INSTALL_URL`."""
    run_install_script()


def run_install_script() -> None:
    """Run `curl -fsSL "$SLAYER_INSTALL_URL" | bash`, or print guidance if unset.

    :return: None
    """
    url = os.environ.get("SLAYER_INSTALL_URL")
    if not url:
        click.echo(
            "SLAYER_INSTALL_URL is not set — set it to the served install "
            "script URL, or re-run the token-slayer installer."
        )
        return
    subprocess.run(f'curl -fsSL "{url}" | bash', shell=True, check=True)
