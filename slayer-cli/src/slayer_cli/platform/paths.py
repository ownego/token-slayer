"""OS-aware filesystem locations. The ONLY place path/OS logic lives."""
from __future__ import annotations
import os
from pathlib import Path
from slayer_cli import constants

class Paths:
    def __init__(self, ns: str) -> None:
        self.ns = ns

    @staticmethod
    def current_ns() -> str:
        return os.environ.get("SLAYER_NS") or constants.DEFAULT_NS

    @property
    def home(self) -> Path:
        return Path(os.environ.get("HOME") or os.path.expanduser("~"))

    @property
    def config_dir(self) -> Path:
        return self.home / ".config" / self.ns

    @property
    def accounts_dir(self) -> Path: return self.config_dir / constants.ACCOUNTS_DIR
    @property
    def state_file(self) -> Path: return self.config_dir / constants.STATE_FILE
    @property
    def history_file(self) -> Path: return self.config_dir / constants.HISTORY_FILE
    @property
    def usage_cache_dir(self) -> Path: return self.config_dir / constants.USAGE_CACHE_DIR
    @property
    def provider_dir(self) -> Path: return self.config_dir / constants.PROVIDER_DIR
    @property
    def active_file(self) -> Path: return self.provider_dir / constants.ACTIVE_FILE

    @property
    def config_file(self) -> Path:
        """User behaviour config file path."""
        return self.config_dir / constants.CONFIG_FILE

    @property
    def sessions_dir(self) -> Path:
        """Runtime session registry directory (per-wrapper state, live PID tracking)."""
        return self.config_dir / constants.SESSIONS_DIR

    @property
    def signals_dir(self) -> Path:
        """PID-namespaced signal file directory for hook↔wrapper communication."""
        return self.config_dir / constants.SIGNALS_DIR

    @property
    def _claude_config_dir(self) -> Path:
        cc = os.environ.get("CLAUDE_CONFIG_DIR")
        return Path(cc) if cc else self.home / ".claude"

    @property
    def claude_credentials_file(self) -> Path:
        return self._claude_config_dir / ".credentials.json"

    @property
    def claude_credentials_backup(self) -> Path:
        """No-clobber backup of the pristine pre-slayer credential file.

        Same directory as `claude_credentials_file`, with `.slayer-bak`
        appended to the filename (honors `CLAUDE_CONFIG_DIR` transitively,
        since it derives from `claude_credentials_file`).

        :return: `claude_credentials_file` with `.slayer-bak` appended to its name.
        """
        f = self.claude_credentials_file
        return f.with_name(f.name + ".slayer-bak")

    @property
    def settings_file(self) -> Path:
        """Claude Code settings file path (~/.claude/settings.json or CLAUDE_CONFIG_DIR).

        Contains hook definitions, model preference, and other Claude Code state.
        """
        return self._claude_config_dir / "settings.json"

    @property
    def claude_json(self) -> Path:
        cc = os.environ.get("CLAUDE_CONFIG_DIR")
        return (Path(cc) / ".claude.json") if cc else self.home / ".claude.json"
