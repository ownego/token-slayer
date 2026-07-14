"""Advisory exclusive file lock to serialise state.json mutations across
processes. POSIX flock; a no-op fallback where fcntl is unavailable."""
from __future__ import annotations
import contextlib
import os
import time
from pathlib import Path
from slayer_cli.errors import SlayerError

try:
    import fcntl
except ImportError:  # pragma: no cover - non-POSIX
    fcntl = None


class LockTimeout(SlayerError):
    """Raised when the lock could not be acquired within the timeout."""


@contextlib.contextmanager
def file_lock(path: Path, *, timeout: float = 10.0):
    """Hold an exclusive advisory lock on `path` for the duration of the block.

    :param path: Lock file path (created 0600 if absent).
    :param timeout: Seconds to wait before raising LockTimeout.
    """
    path = Path(path)
    if fcntl is None:  # pragma: no cover
        yield
        return
    fd = os.open(path, os.O_CREAT | os.O_RDWR, 0o600)
    deadline = time.monotonic() + timeout
    try:
        while True:
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise LockTimeout(f"timed out acquiring {path}")
                time.sleep(0.05)
        yield
    finally:
        with contextlib.suppress(OSError):
            fcntl.flock(fd, fcntl.LOCK_UN)
        os.close(fd)
