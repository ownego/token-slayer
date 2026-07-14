"""Tests for `accounts.provisioned.pull_and_setup` — pulls admin-provisioned
grants from the server and configures Claude Code."""
from __future__ import annotations

import json

import httpx
import pytest

from slayer_cli.accounts import provisioned
from slayer_cli.errors import ProvisioningError
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
    # attribution reconciled for the active (first) account: provider file written
    provider = json.loads((p.active_file).read_text())
    assert provider["email"] == "shared@org.com" and provider["org_uuid"] == "org-1"
    creds = json.loads(p.claude_credentials_file.read_text())["claudeAiOauth"]
    assert creds["refreshToken"] == "ort01-REFRESH" and creds["accessToken"] == "sk-ant-oat01-TESTTOKEN"
    assert creds["expiresAt"] == 1_800_000_000_000


def test_pull_raises_clean_error_on_http_401(tmp_path, monkeypatch):
    """A 401 from the server surfaces a ProvisioningError (not a raw httpx
    traceback), hinting the hook token/namespace is wrong."""
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    p = Paths("token_slayer")

    def handler(req):
        return httpx.Response(401, json={"message": "Unauthenticated."})

    client = httpx.Client(base_url="https://ts.example", transport=httpx.MockTransport(handler))
    with pytest.raises(ProvisioningError):
        provisioned.pull_and_setup(p, "BADTOK", client=client)


def test_pull_raises_clean_error_on_network_failure(tmp_path, monkeypatch):
    """A transport/network failure surfaces a ProvisioningError, not a raw
    httpx exception."""
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    p = Paths("token_slayer")

    def handler(req):
        raise httpx.ConnectError("boom")

    client = httpx.Client(base_url="https://ts.example", transport=httpx.MockTransport(handler))
    with pytest.raises(ProvisioningError):
        provisioned.pull_and_setup(p, "TOK", client=client)


def test_pull_stores_refresh_and_expiry_in_every_slot(tmp_path, monkeypatch):
    """Every provisioned slot (not just the active/first one) stores its refresh
    token + expiry (seconds→ms), so non-active provisioned accounts can
    self-refresh during auto-switch usage polling."""
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    p = Paths("token_slayer")

    def handler(req):
        return httpx.Response(200, json={"accounts": [
            {"name": "a@x.com", "email": "a@x.com", "org_uuid": "o-a",
             "access_token": "sk-ant-oat01-A", "refresh_token": "ort01-A", "expires_at": 1_800_000_000},
            {"name": "b@x.com", "email": "b@x.com", "org_uuid": "o-b",
             "access_token": "sk-ant-oat01-B", "refresh_token": "ort01-B", "expires_at": 1_700_000_000},
        ]})

    client = httpx.Client(base_url="https://ts.example", transport=httpx.MockTransport(handler))
    provisioned.pull_and_setup(p, "TOK", client=client)

    slot_b = json.loads((p.accounts_dir / "b@x.com.json").read_text())
    assert slot_b["refresh_token"] == "ort01-B"        # non-active slot keeps its refresh token
    assert slot_b["expires_at"] == 1_700_000_000_000   # seconds → milliseconds


def test_pull_raises_when_the_active_credential_write_does_not_take_effect(tmp_path, monkeypatch):
    """A real incident: the server recorded a successful claim (`claimed_at`
    set) but the client's local credential write silently never took effect
    (a disk/permission quirk, a race with something else touching the file),
    leaving the user's live Claude session on their old account while
    `setup` still reported success. Verify the write actually landed —
    read back the live grant and compare — and raise instead of reporting
    success when it didn't, rather than silently leaving the account
    inactive."""
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    p = Paths("token_slayer")

    def handler(req):
        return httpx.Response(200, json={"accounts": [
            {"name": "shared@org.com", "email": "shared@org.com", "org_uuid": "org-1",
             "access_token": "sk-ant-oat01-TESTTOKEN", "refresh_token": "ort01-REFRESH",
             "expires_at": 1_800_000_000}]})

    monkeypatch.setattr("slayer_cli.accounts.provisioned.credstore.write_active_full", lambda *a, **k: None)

    client = httpx.Client(base_url="https://ts.example", transport=httpx.MockTransport(handler))
    with pytest.raises(ProvisioningError):
        provisioned.pull_and_setup(p, "TOK", client=client)


def test_pull_raises_when_attribution_does_not_reflect_the_setup_account(tmp_path, monkeypatch):
    """A second, independently-failing step in the same real incident: the
    live credential write can succeed (Claude Code itself now uses the new
    account) while the SEPARATE attribution write
    (account-provider/active.json, what the hook actually reads) silently
    doesn't take effect — leaving every subsequent event attributed to
    whichever account was active before, even though `setup` printed
    success and the TUI (which only reads state.json) shows the new
    account selected."""
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    p = Paths("token_slayer")

    def handler(req):
        return httpx.Response(200, json={"accounts": [
            {"name": "shared@org.com", "email": "shared@org.com", "org_uuid": "org-1",
             "access_token": "sk-ant-oat01-TESTTOKEN", "refresh_token": "ort01-REFRESH",
             "expires_at": 1_800_000_000}]})

    # reconcile_active "succeeds" (no exception) but never actually updates
    # active.json -- simulates the real-world silent no-op.
    monkeypatch.setattr("slayer_cli.accounts.provisioned.attribution.reconcile_active", lambda *a, **k: None)

    client = httpx.Client(base_url="https://ts.example", transport=httpx.MockTransport(handler))
    with pytest.raises(ProvisioningError):
        provisioned.pull_and_setup(p, "TOK", client=client)


def test_base_url_defaults_to_production(monkeypatch):
    """Without SLAYER_API_BASE set, real installs must hit PRODUCTION — not
    staging. Every user's install script never sets this env var, so this
    default IS what every real `token-slayer setup` call uses."""
    monkeypatch.delenv("SLAYER_API_BASE", raising=False)
    assert provisioned._base_url() == "https://token-slayer.ownego.com"


def test_base_url_respects_explicit_override(monkeypatch):
    """A dev/staging override still works when explicitly set."""
    monkeypatch.setenv("SLAYER_API_BASE", "https://ts.tungot.dev")
    assert provisioned._base_url() == "https://ts.tungot.dev"
