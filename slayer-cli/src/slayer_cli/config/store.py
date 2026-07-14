"""Load/save/set the behaviour config (atomic 0600), merged with defaults."""
from __future__ import annotations
import os
from slayer_cli.config.model import Config
from slayer_cli.errors import SlayerError
from slayer_cli.platform.paths import Paths


class ConfigError(SlayerError):
    """Raised on an unknown key or an invalid value."""


_STRATEGY_CYCLE: tuple[str, ...] = ("manual", "balanced", "drain")
"""The fixed manual→balanced→drain→manual cycle order used by `next_strategy_kind`."""


def next_strategy_kind(current: str) -> str:
    """Return the next `strategy.kind` in the manual->balanced->drain->manual
    cycle, used by the TUI's strategy-cycle keybinding.

    :param current: The current `strategy.kind` value.
    :return: The next kind in the cycle; an unrecognized `current` resets to
        `"manual"` rather than raising.
    """
    try:
        index = _STRATEGY_CYCLE.index(current)
    except ValueError:
        return _STRATEGY_CYCLE[0]
    return _STRATEGY_CYCLE[(index + 1) % len(_STRATEGY_CYCLE)]


def load(paths: Paths) -> Config:
    """Return the config merged with defaults; a missing/corrupt file yields defaults.

    :param paths: Resolved OS paths for this namespace.
    :return: Loaded config with defaults merged in.
    """
    path = paths.config_file
    if not path.is_file():
        return Config()
    try:
        return Config.model_validate_json(path.read_text())
    except ValueError:
        return Config()


def save(paths: Paths, cfg: Config) -> None:
    """Write the config atomically at mode 0600 (dir 0700).

    :param paths: Resolved OS paths for this namespace.
    :param cfg: Config to save.
    :return: None
    """
    d = paths.config_dir
    d.mkdir(parents=True, exist_ok=True)
    os.chmod(d, 0o700)
    tmp = paths.config_file.with_suffix(".tmp")
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as handle:
        handle.write(cfg.model_dump_json(indent=2))
    tmp.replace(paths.config_file)


def _pct(v: str) -> int:
    """Parse and validate a percentage value (0–100).

    :param v: String value (with optional % suffix).
    :return: Integer percentage.
    :raises ConfigError: If value is not a valid percentage.
    """
    n = int(v.rstrip("%"))
    if not 0 <= n <= 100:
        raise ConfigError("percentage must be 0–100")
    return n


def _bool(v: str) -> bool:
    """Parse and validate a boolean value.

    :param v: String value (true/yes/on/1 or false/no/off/0).
    :return: Boolean value.
    :raises ConfigError: If value is not a recognized boolean.
    """
    if v.lower() in ("true", "yes", "on", "1"):
        return True
    if v.lower() in ("false", "no", "off", "0"):
        return False
    raise ConfigError(f"expected a boolean, got {v!r}")


def set_value(cfg: Config, key: str, value: str) -> Config:
    """Return a copy of `cfg` with dotted `key` set to `value` (string-parsed).

    Supports keys:
    - thresholds.five_hour (0–100%)
    - thresholds.seven_day (0–100%)
    - strategy.kind (manual|balanced|drain)
    - strategy.order (comma-separated list)
    - auto_switch_on_threshold (boolean)
    - auto_switch_on_rate_limit (boolean)
    - auto_resume (boolean)
    - auto_message (string)
    - wait_for_reset (boolean)
    - retry_on_api_error (boolean)

    :param cfg: Config to mutate.
    :param key: Dotted key path (e.g. "strategy.kind").
    :param value: String value to set.
    :return: Updated config (deep copy).
    :raises ConfigError: On unknown key or invalid value.
    """
    c = cfg.model_copy(deep=True)
    if key == "thresholds.five_hour":
        c.thresholds.five_hour = _pct(value)
    elif key == "thresholds.seven_day":
        c.thresholds.seven_day = _pct(value)
    elif key == "strategy.kind":
        if value not in ("manual", "balanced", "drain"):
            raise ConfigError("strategy.kind must be manual|balanced|drain")
        c.strategy.kind = value
    elif key == "strategy.order":
        c.strategy.order = [p.strip() for p in value.split(",") if p.strip()]
    elif key == "auto_switch_on_threshold":
        c.auto_switch_on_threshold = _bool(value)
    elif key == "auto_switch_on_rate_limit":
        c.auto_switch_on_rate_limit = _bool(value)
    elif key == "auto_resume":
        c.auto_resume = _bool(value)
    elif key == "auto_message":
        c.auto_message = "" if value == '""' else value
    elif key == "wait_for_reset":
        c.wait_for_reset = _bool(value)
    elif key == "retry_on_api_error":
        c.retry_on_api_error = _bool(value)
    else:
        raise ConfigError(f"unknown key {key!r}")
    return c
