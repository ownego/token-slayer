import os
from slayer_cli.autoswitch import signals
from slayer_cli.platform.paths import Paths

def test_write_read_consume(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer"); pid = os.getpid()
    assert signals.read(p, pid, signals.STOPPED) is None            # absent
    signals.write(p, pid, signals.STOPPED, {"sessionId": "s1"})
    assert signals.read(p, pid, signals.STOPPED) == {"sessionId": "s1"}
    signals.consume(p, pid, signals.STOPPED)
    assert signals.read(p, pid, signals.STOPPED) is None            # consumed

def test_pid_namespaced_and_cleanup(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    signals.write(p, 111, signals.RATE_LIMITED, {"message": "x"})
    signals.write(p, 222, signals.STOPPED, None)
    assert signals.read(p, 222, signals.RATE_LIMITED) is None       # 222 doesn't see 111's
    signals.cleanup_for_pid(p, 111)
    assert signals.read(p, 111, signals.RATE_LIMITED) is None
    assert signals.read(p, 222, signals.STOPPED) == {}             # 222 untouched (marker → {})
