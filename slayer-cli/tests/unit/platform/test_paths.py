from slayer_cli.platform.paths import Paths

def test_paths_under_config_ns(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    p = Paths("token_slayer_stg")
    assert p.config_dir == tmp_path / ".config" / "token_slayer_stg"
    assert p.active_file == p.config_dir / "account-provider" / "active.json"
    assert p.claude_credentials_file == tmp_path / ".claude" / ".credentials.json"

def test_claude_config_dir_override(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.setenv("CLAUDE_CONFIG_DIR", str(tmp_path / "cc"))
    p = Paths("token_slayer")
    assert p.claude_credentials_file == tmp_path / "cc" / ".credentials.json"

def test_current_ns_env(monkeypatch):
    monkeypatch.setenv("SLAYER_NS", "token_slayer_stg")
    assert Paths.current_ns() == "token_slayer_stg"
    monkeypatch.delenv("SLAYER_NS")
    assert Paths.current_ns() == "token_slayer"
