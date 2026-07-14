import pytest
from slayer_cli.platform import lock


def test_lock_acquires_and_releases(tmp_path):
    p = tmp_path / ".lock"
    with lock.file_lock(p):
        assert p.exists()
    with lock.file_lock(p):     # re-acquire after release
        pass


def test_lock_timeout_when_held(tmp_path):
    import fcntl, os
    p = tmp_path / ".lock"
    fd = os.open(p, os.O_CREAT | os.O_RDWR, 0o600)
    fcntl.flock(fd, fcntl.LOCK_EX)
    try:
        with pytest.raises(lock.LockTimeout):
            with lock.file_lock(p, timeout=0.2):
                pass
    finally:
        fcntl.flock(fd, fcntl.LOCK_UN); os.close(fd)
