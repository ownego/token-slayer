"""TTL file cache (one file per key), 0600."""
from __future__ import annotations
import os, time
from pathlib import Path

class TTLCache:
    def __init__(self, dir: Path, ttl: int) -> None:
        self.dir, self.ttl = dir, ttl

    def get(self, key: str) -> str | None:
        f = self.dir / key
        if not f.is_file():
            return None
        if time.time() - f.stat().st_mtime > self.ttl:
            return None
        return f.read_text()

    def put(self, key: str, value: str) -> None:
        self.dir.mkdir(parents=True, exist_ok=True)
        f = self.dir / key
        f.write_text(value)
        os.chmod(f, 0o600)
