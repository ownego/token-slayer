import os
from slayer_cli.autoswitch import registry
from slayer_cli.platform.paths import Paths

def test_update_and_list_self(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    registry.update_self(p, lambda e: e.__setitem__("state", "running") or e.__setitem__("account", "work"))
    entries = registry.list(p)
    assert len(entries) == 1 and entries[0].state == "running" and entries[0].account == "work"
    assert entries[0].pid == os.getpid()
    fpath = p.sessions_dir / f"{os.getpid()}.json"
    assert fpath.stat().st_mode & 0o777 == 0o600
    assert p.sessions_dir.stat().st_mode & 0o777 == 0o700

def test_list_prunes_dead_pid(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    dead = p.sessions_dir; dead.mkdir(parents=True, exist_ok=True)
    (dead / "999999.json").write_text('{"pid":999999,"state":"running","account":"x","cwd":"/","updated_at":1}')
    assert registry.list(p) == []   # dead pid pruned

def test_list_skips_corrupt_json(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    p.sessions_dir.mkdir(parents=True)
    (p.sessions_dir / "123.json").write_text("{invalid json")
    (p.sessions_dir / f"{os.getpid()}.json").write_text(f'{{"pid":{os.getpid()},"state":"running","account":null,"cwd":"/","updated_at":1}}')
    entries = registry.list(p)
    assert len(entries) == 1 and entries[0].pid == os.getpid()
