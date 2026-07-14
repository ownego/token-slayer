"""`config` subcommand group: show and set user behaviour configuration."""
from __future__ import annotations

import click

from slayer_cli.config import store

__all__ = ["command"]


@click.group(name="config", hidden=True)
@click.pass_obj
def command(services) -> None:
    """Manage user behaviour configuration (strategy, thresholds, auto-switch rules).

    Subcommands: show (print current config), set (update a config key).
    """
    pass


@command.command(name="show")
@click.pass_obj
def show(services) -> None:
    """Print the current configuration as JSON.

    :param services: Shared services (paths, store).
    :return: None
    """
    try:
        cfg = store.load(services.paths)
        click.echo(cfg.model_dump_json(indent=2))
    except store.ConfigError as e:
        raise click.ClickException(str(e))


@command.command(name="set")
@click.argument("key")
@click.argument("value")
@click.pass_obj
def set_cmd(services, key: str, value: str) -> None:
    """Update a configuration key.

    Examples:
      token-slayer config set strategy.kind balanced
      token-slayer config set thresholds.seven_day 85
      token-slayer config set auto_resume false

    :param services: Shared services (paths, store).
    :param key: Dotted key path (e.g. strategy.kind).
    :param value: New value as string.
    :return: None
    """
    try:
        cfg = store.load(services.paths)
        cfg = store.set_value(cfg, key, value)
        store.save(services.paths, cfg)
        click.echo(f"Config updated: {key} = {value}")
    except store.ConfigError as e:
        raise click.ClickException(str(e))
