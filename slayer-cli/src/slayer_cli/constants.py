"""Project-wide constants (paths are computed in platform.paths, not here)."""
from __future__ import annotations

DEFAULT_NS = "token_slayer"
ACCOUNTS_DIR = "accounts"          # ~/.config/<ns>/accounts/<slot>.json
STATE_FILE = "state.json"
HISTORY_FILE = "swap-history.jsonl"
USAGE_CACHE_DIR = "usage-cache"
PROVIDER_DIR = "account-provider"
ACTIVE_FILE = "active.json"        # <provider>/active.json
CONFIG_FILE = "config.json"
SESSIONS_DIR = "runtime/sessions"  # ~/.config/<ns>/runtime/sessions/<pid>.json
SIGNALS_DIR = "runtime/signals"    # ~/.config/<ns>/runtime/signals/<pid>-<name>
USAGE_TTL_SECONDS = 300
