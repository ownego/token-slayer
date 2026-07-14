import json, sys
from slayer_cli.platform.paths import Paths
from slayer_cli import credstore

def test_file_store_merges_claudeaioauth(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path)); monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    monkeypatch.setattr(sys, "platform", "linux")
    p = Paths("token_slayer")
    p.claude_credentials_file.parent.mkdir(parents=True)
    p.claude_credentials_file.write_text(json.dumps({"mcpOAuth": {"keep": 1}}))
    credstore.write_active_token(p, "sk-ant-oat01-TESTTOKEN")
    data = json.loads(p.claude_credentials_file.read_text())
    assert data["mcpOAuth"] == {"keep": 1}                       # preserved
    assert data["claudeAiOauth"]["accessToken"] == "sk-ant-oat01-TESTTOKEN"
    assert data["claudeAiOauth"]["refreshToken"] is None
    assert p.claude_credentials_file.stat().st_mode & 0o777 == 0o600
    assert credstore.read_active_token(p) == "sk-ant-oat01-TESTTOKEN"

def test_write_backs_up_existing_credential_before_overwrite(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path)); monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    p = Paths("token_slayer")
    p.claude_credentials_file.parent.mkdir(parents=True)
    original = json.dumps({"claudeAiOauth": {"accessToken": "sk-ant-oat01-ORIGINAL"}})
    p.claude_credentials_file.write_text(original)
    credstore.write_active_token(p, "sk-ant-oat01-NEWTOKEN")
    backup = p.claude_credentials_backup
    assert backup == p.claude_credentials_file.with_name(p.claude_credentials_file.name + ".slayer-bak")
    assert backup.is_file()
    assert backup.stat().st_mode & 0o777 == 0o600
    assert json.loads(backup.read_text())["claudeAiOauth"]["accessToken"] == "sk-ant-oat01-ORIGINAL"

def test_write_second_time_does_not_clobber_existing_backup(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path)); monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    p = Paths("token_slayer")
    p.claude_credentials_file.parent.mkdir(parents=True)
    p.claude_credentials_file.write_text(json.dumps({"claudeAiOauth": {"accessToken": "sk-ant-oat01-ORIGINAL"}}))
    credstore.write_active_token(p, "sk-ant-oat01-FIRSTSWITCH")
    credstore.write_active_token(p, "sk-ant-oat01-SECONDSWITCH")
    backup_data = json.loads(p.claude_credentials_backup.read_text())
    assert backup_data["claudeAiOauth"]["accessToken"] == "sk-ant-oat01-ORIGINAL"

def test_write_with_no_existing_credential_creates_no_backup(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path)); monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    p = Paths("token_slayer")
    credstore.write_active_token(p, "sk-ant-oat01-FIRSTEVER")
    assert not p.claude_credentials_backup.exists()

def test_restore_backup_moves_pristine_credential_back(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path)); monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    p = Paths("token_slayer")
    p.claude_credentials_file.parent.mkdir(parents=True)
    p.claude_credentials_file.write_text(json.dumps({"claudeAiOauth": {"accessToken": "sk-ant-oat01-ORIGINAL"}}))
    credstore.write_active_token(p, "sk-ant-oat01-NEWTOKEN")
    from slayer_cli.credstore import file_store
    restored = file_store.restore_backup(p.claude_credentials_file)
    assert restored is True
    assert json.loads(p.claude_credentials_file.read_text())["claudeAiOauth"]["accessToken"] == "sk-ant-oat01-ORIGINAL"
    assert p.claude_credentials_file.stat().st_mode & 0o777 == 0o600
    assert not p.claude_credentials_backup.exists()

def test_restore_backup_returns_false_when_no_backup_exists(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path)); monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    p = Paths("token_slayer")
    from slayer_cli.credstore import file_store
    assert file_store.restore_backup(p.claude_credentials_file) is False

def test_patch_oauth_account(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path)); monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    p = Paths("token_slayer")
    p.claude_json.write_text(json.dumps({"other": 1}))
    from slayer_cli.credstore import claude_json
    claude_json.patch_oauth_account(p, email="a@b.com", uuid="u1", org="org1")
    d = json.loads(p.claude_json.read_text())
    assert d["other"] == 1
    assert d["oauthAccount"] == {"emailAddress": "a@b.com", "accountUuid": "u1", "organizationUuid": "org1"}

def test_write_full_keeps_real_refresh_token(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path)); monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    monkeypatch.setattr(sys, "platform", "linux")
    p = Paths("token_slayer")
    p.claude_credentials_file.parent.mkdir(parents=True)
    p.claude_credentials_file.write_text(json.dumps({"mcpOAuth": {"keep": 1}}))
    credstore.write_active_full(p, "sk-ant-oat01-TESTTOKEN", "ort01-REFRESH", 1_800_000_000_000)
    data = json.loads(p.claude_credentials_file.read_text())["claudeAiOauth"]
    assert data["accessToken"] == "sk-ant-oat01-TESTTOKEN"
    assert data["refreshToken"] == "ort01-REFRESH"      # real, NOT null
    assert data["expiresAt"] == 1_800_000_000_000
    assert json.loads(p.claude_credentials_file.read_text())["mcpOAuth"] == {"keep": 1}
    assert p.claude_credentials_file.stat().st_mode & 0o777 == 0o600

def test_read_active_full_returns_whole_oauth_block(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path)); monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    monkeypatch.setattr(sys, "platform", "linux")
    p = Paths("token_slayer")
    p.claude_credentials_file.parent.mkdir(parents=True)
    p.claude_credentials_file.write_text(json.dumps({"claudeAiOauth": {
        "accessToken": "sk-ant-oat01-TESTTOKEN", "refreshToken": "sk-ant-ort01-TESTREFRESH",
        "expiresAt": 1_800_000_000_000, "scopes": ["user:inference"]}}))
    block = credstore.read_active_full(p)
    assert block["refreshToken"] == "sk-ant-ort01-TESTREFRESH"
    assert block["expiresAt"] == 1_800_000_000_000

def test_read_active_full_none_when_missing(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path)); monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    monkeypatch.setattr(sys, "platform", "linux")
    assert credstore.read_active_full(Paths("token_slayer")) is None
