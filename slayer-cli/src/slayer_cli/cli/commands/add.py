"""`add` subcommand: create a new account slot, either by snapshotting the
active Claude Code login or by driving a fresh PKCE login. See
`.ai/domain/account-slots.md`.

`add_snapshot`/`add_via_login` are imported as module attributes (not
`from ... import`) so tests can `monkeypatch.setattr` them here without
touching `accounts.add`."""
from __future__ import annotations

import click

from slayer_cli.accounts.add import add_snapshot, add_via_login
from slayer_cli.errors import SlayerError
from slayer_cli.models.account import Account

__all__ = ["command"]


@click.command(name="add")
@click.argument("name")
@click.option("--login", is_flag=True,
              help="Drive a fresh PKCE login instead of snapshotting the active credential.")
@click.pass_obj
def command(services, name: str, login: bool) -> None:
    """Add a new account slot named NAME.

    Without --login, snapshots the machine's currently active Claude Code
    credential; with --login, drives a fresh PKCE login instead.
    """
    try:
        if login:
            account = add_via_login(services.store, services.paths, name,
                                     code_provider=_prompt_for_code)
        else:
            account = add_snapshot(services.store, services.paths, name)
    except SlayerError as exc:
        raise click.ClickException(str(exc)) from exc
    click.echo(f"Added account slot '{account.name}' ({_detail(account)})")


def _detail(account: Account) -> str:
    """Return a token-free one-line detail string for `account`.

    :param account: Account slot to describe.
    :return: `email` or `org_uuid`, or "unknown" if neither is set.
    """
    return account.email or account.org_uuid or "unknown"


def _prompt_for_code(authorize_url: str) -> str:
    """Print the authorize URL and prompt the user to paste back the code.

    :param authorize_url: PKCE authorize URL to open in a browser.
    :return: The `code#state` value the user pasted.
    """
    click.echo(f"Open this URL to authorize: {authorize_url}")
    return click.prompt("Paste the code")
