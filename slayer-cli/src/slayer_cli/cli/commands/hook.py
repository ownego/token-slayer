"""`hook` subcommand group: entry points Claude Code invokes directly as
configured hooks. Each subcommand reads hook JSON from stdin and delegates
to `autoswitch.hooks`, which is TS_WRAPPED-gated (a no-op outside
`token-slayer run`, so these hooks are harmless to install unconditionally)."""
from __future__ import annotations

import sys

import click

from slayer_cli.autoswitch import hooks

__all__ = ["command"]


@click.group(name="hook")
def command() -> None:
    """Hook entry points invoked by Claude Code (SessionStart, Stop, failure, prompt-submit)."""


@command.command(name="session-start")
def session_start() -> None:
    """Handle the SessionStart hook.

    :return: None
    """
    hooks.session_start(sys.stdin, sys.stdout)


@command.command(name="stop")
def stop() -> None:
    """Handle the Stop hook.

    :return: None
    """
    hooks.stop(sys.stdin)


@command.command(name="rate-limit")
def rate_limit() -> None:
    """Handle a failure hook (rate-limit/API-error classification).

    :return: None
    """
    hooks.rate_limit(sys.stdin)


@command.command(name="prompt-submit")
def prompt_submit() -> None:
    """Handle the UserPromptSubmit hook (`/switch`, `/ts:` interception).

    :return: None
    """
    hooks.prompt_submit(sys.stdin, sys.stdout)
