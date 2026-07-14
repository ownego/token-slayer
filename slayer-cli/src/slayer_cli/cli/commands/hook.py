"""`hook` subcommand group: entry points Claude Code invokes directly as
configured hooks. Most subcommands read hook JSON from stdin and delegate
to `autoswitch.hooks`, which is TS_WRAPPED-gated (a no-op outside
`token-slayer run`, so these hooks are harmless to install unconditionally).
`usage-refresh` is the exception: it always runs (no TS_WRAPPED gate), since
it only warms the local usage cache and is independent of auto-switch."""
from __future__ import annotations

import sys

import click

from slayer_cli.autoswitch import hooks
from slayer_cli.platform.paths import Paths
from slayer_cli.usage import stop_refresh

__all__ = ["command"]


@click.group(name="hook", hidden=True)
def command() -> None:
    """Hook entry points invoked by Claude Code (SessionStart, Stop, failure, prompt-submit)."""


@command.command(name="session-start")
def session_start() -> None:
    """Handle the SessionStart hook.
    """
    hooks.session_start(sys.stdin, sys.stdout)


@command.command(name="stop")
def stop() -> None:
    """Handle the Stop hook.
    """
    hooks.stop(sys.stdin)


@command.command(name="rate-limit")
def rate_limit() -> None:
    """Handle a failure hook (rate-limit/API-error classification).
    """
    hooks.rate_limit(sys.stdin)


@command.command(name="prompt-submit")
def prompt_submit() -> None:
    """Handle the UserPromptSubmit hook (`/switch`, `/ts:` interception).
    """
    hooks.prompt_submit(sys.stdin, sys.stdout)


@command.command(name="usage-refresh")
def usage_refresh() -> None:
    """Handle the Stop hook by warming the active account's usage cache.

    Always runs (not TS_WRAPPED-gated) — independent of auto-switch.
    """
    try:
        sys.stdin.read()
    except Exception:  # noqa: BLE001 - stdin content is unused; never block the hook on it
        pass
    stop_refresh.refresh_active_on_stop(Paths(Paths.current_ns()))
