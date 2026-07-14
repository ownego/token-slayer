"""Swap history (JSONL append/recent). See `.ai/domain/switching.md`."""
from __future__ import annotations

import os
from pathlib import Path

from slayer_cli.models.history import SwapHistoryEntry
from slayer_cli.platform.paths import Paths


class SwapHistory:
    """Reads and appends to the swap-history.jsonl file.

    The history file is a JSONL (JSON Lines) append-only log of account
    switches. Each line is a `SwapHistoryEntry` serialized as JSON.
    """

    def __init__(self, paths: Paths) -> None:
        """Initialize with resolved filesystem paths.

        :param paths: Resolved OS paths for this namespace.
        """
        self._paths = paths

    def append(self, entry: SwapHistoryEntry) -> None:
        """Append a swap history entry to the JSONL file.

        Creates the file and parent directories with appropriate permissions
        (0600 for file, 0700 for directories) if they don't exist.

        :param entry: The swap history entry to append.
        :return: None
        """
        self._harden_dir(self._paths.config_dir)
        path = self._paths.history_file

        # Ensure file exists with 0600 permissions if it doesn't exist yet
        if not path.exists():
            fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
            os.close(fd)

        # Append the entry as JSONL
        with open(path, "a") as f:
            f.write(entry.model_dump_json(by_alias=True) + "\n")

    def recent(self, n: int = 20) -> list[SwapHistoryEntry]:
        """Return the last n entries from the history, newest first.

        If the history file doesn't exist, returns an empty list.
        Parses each non-empty line as JSON.

        :param n: Number of recent entries to return (default 20).
        :return: List of SwapHistoryEntry, newest first.
        """
        path = self._paths.history_file

        if not path.is_file():
            return []

        lines = path.read_text().strip().split("\n")
        # Filter empty lines, parse each as SwapHistoryEntry
        entries = []
        for line in lines:
            if line.strip():
                entries.append(SwapHistoryEntry.model_validate_json(line))

        # Return last n entries, reversed (newest first)
        return list(reversed(entries[-n:]))

    @staticmethod
    def _harden_dir(path: Path) -> None:
        """Create path (and parents) if absent and force it to 0700.

        A credential directory is never left group/world-listable.

        :param path: Directory to create and harden.
        :return: None
        """
        path.mkdir(parents=True, exist_ok=True)
        os.chmod(path, 0o700)
