"""Tests for `accounts.provisioned.pull_and_setup` — pulls admin-provisioned
grants from the server and configures Claude Code."""
from __future__ import annotations

import json

import httpx

from slayer_cli.accounts import provisioned
from slayer_cli.platform.paths import Paths


def test_pull_writes_full_credential_and_upserts_slot(tmp_path, monkeypatch):
    """pull_and_setup upserts an account slot per returned grant and writes
    the FIRST grant as the active full credential (real refresh token,
    expiresAt converted from seconds to milliseconds)."""
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    p = Paths("token_slayer")

    def handler(req):
        assert req.headers["Authorization"] == "Bearer HOOKTOK"
        return httpx.Response(200, json={"accounts": [
            {"name": "shared@org.com", "email": "shared@org.com", "org_uuid": "org-1",
             "access_token": "sk-ant-oat01-TESTTOKEN", "refresh_token": "ort01-REFRESH",
             "expires_at": 1_800_000_000}]})

    client = httpx.Client(base_url="https://ts.example", transport=httpx.MockTransport(handler))
    names = provisioned.pull_and_setup(p, "HOOKTOK", client=client)
    assert names == ["shared@org.com"]
    # slot upserted (no token in the returned names)
    assert (p.accounts_dir / "shared@org.com.json").is_file()
    # active credential written with the REAL refresh token
    creds = json.loads(p.claude_credentials_file.read_text())["claudeAiOauth"]
    assert creds["refreshToken"] == "ort01-REFRESH" and creds["accessToken"] == "sk-ant-oat01-TESTTOKEN"
