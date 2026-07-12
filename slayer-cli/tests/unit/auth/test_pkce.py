"""Tests for the PKCE login flow (`auth.pkce.start`/`auth.pkce.exchange`)."""
from __future__ import annotations

from urllib.parse import parse_qs, urlparse

import httpx
import pytest

from slayer_cli.auth import pkce
from slayer_cli.errors import SlayerError


def _client(handler):
    return httpx.Client(transport=httpx.MockTransport(handler))


def test_start_returns_authorize_url_with_expected_params():
    """start() builds the exact Claude Code public-client authorize URL,
    with a fresh S256 challenge and state on every call."""
    url, verifier, state = pkce.start()
    parsed = urlparse(url)
    assert f"{parsed.scheme}://{parsed.netloc}{parsed.path}" == "https://claude.com/cai/oauth/authorize"
    params = parse_qs(parsed.query)
    assert params["client_id"] == ["9d1c250a-e61b-44d9-88ed-5944d1962f5e"]
    assert params["code_challenge_method"] == ["S256"]
    assert params["redirect_uri"] == ["https://platform.claude.com/oauth/code/callback"]
    assert params["response_type"] == ["code"]
    assert params["state"] == [state]
    assert params["code_challenge"][0]
    assert verifier


def test_exchange_posts_json_body_and_returns_access_token():
    """exchange() splits the pasted `code#state` value, POSTs the exact JSON
    body to the token endpoint, and returns `access_token` from the response."""
    captured = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["url"] = str(request.url)
        captured["body"] = httpx.Request("POST", request.url, content=request.content).content
        import json

        captured["json"] = json.loads(request.content)
        return httpx.Response(200, json={"access_token": "sk-ant-oat01-RECEIVED"})

    token = pkce.exchange("theCODE#theSTATE", "theVERIFIER", client=_client(handler))

    assert captured["url"] == "https://platform.claude.com/v1/oauth/token"
    body = captured["json"]
    assert body["grant_type"] == "authorization_code"
    assert body["code"] == "theCODE"
    assert body["state"] == "theSTATE"
    assert body["code_verifier"] == "theVERIFIER"
    assert body["client_id"] == "9d1c250a-e61b-44d9-88ed-5944d1962f5e"
    assert body["redirect_uri"] == "https://platform.claude.com/oauth/code/callback"
    assert token == "sk-ant-oat01-RECEIVED"


def test_exchange_without_hash_falls_back_to_empty_state():
    """A pasted value with no `#` is treated as the code alone, state ''."""
    captured = {}

    def handler(request: httpx.Request) -> httpx.Response:
        import json

        captured["json"] = json.loads(request.content)
        return httpx.Response(200, json={"access_token": "sk-ant-oat01-X"})

    pkce.exchange("justthecode", "v", client=_client(handler))
    assert captured["json"]["code"] == "justthecode"
    assert captured["json"]["state"] == ""


def test_exchange_raises_typed_error_on_failure_without_leaking_secrets():
    """A non-2xx token-endpoint response raises a typed SlayerError whose
    message is static — never the code, verifier, or any token."""

    def handler(_request: httpx.Request) -> httpx.Response:
        return httpx.Response(400, json={"error": "invalid_grant"})

    with pytest.raises(SlayerError) as exc_info:
        pkce.exchange("secretCODE#secretSTATE", "secretVERIFIER", client=_client(handler))

    message = str(exc_info.value)
    assert "secretCODE" not in message
    assert "secretSTATE" not in message
    assert "secretVERIFIER" not in message
