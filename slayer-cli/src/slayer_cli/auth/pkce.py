"""PKCE login against Claude Code's public OAuth client. The redirect target
is fixed to Anthropic's own callback page (this is Anthropic's public client,
not ours) so an automated redirect capture is impossible — the human always
authenticates in their own browser and pastes the resulting `code#state`
back into the CLI. This module never handles the user's Anthropic
credentials, only the pasted code."""
from __future__ import annotations

import base64
import hashlib
import os
from urllib.parse import urlencode

import httpx

from slayer_cli.errors import LoginError
from slayer_cli.platform.http import client as make_client

AUTHORIZE_URL = "https://claude.com/cai/oauth/authorize"
TOKEN_URL = "https://platform.claude.com/v1/oauth/token"
CLIENT_ID = "9d1c250a-e61b-44d9-88ed-5944d1962f5e"
REDIRECT_URI = "https://platform.claude.com/oauth/code/callback"
SCOPE = "org:create_api_key user:profile user:inference user:sessions:claude_code user:mcp_servers user:file_upload"


def _b64url_nopad(raw: bytes) -> str:
    """Base64url-encode `raw` with `=` padding stripped, per RFC 7636.

    :param raw: Bytes to encode.
    :return: Padding-free base64url string.
    """
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode("ascii")


def start() -> tuple[str, str, str]:
    """Generate a fresh PKCE challenge and build the authorize URL to show
    the user.

    :return: `(authorize_url, code_verifier, state)`. The verifier must be
        kept and passed to `exchange()`; the state is embedded in the URL
        and echoed back by Anthropic in the pasted code.
    """
    code_verifier = _b64url_nopad(os.urandom(32))
    code_challenge = _b64url_nopad(hashlib.sha256(code_verifier.encode("ascii")).digest())
    state = _b64url_nopad(os.urandom(16))
    params = {
        "code": "true",
        "client_id": CLIENT_ID,
        "response_type": "code",
        "redirect_uri": REDIRECT_URI,
        "scope": SCOPE,
        "code_challenge": code_challenge,
        "code_challenge_method": "S256",
        "state": state,
    }
    return f"{AUTHORIZE_URL}?{urlencode(params)}", code_verifier, state


def exchange(code: str, verifier: str, client: httpx.Client | None = None) -> str:
    """Exchange a pasted `code#state` value for an access token.

    :param code: The value the user pasted from Anthropic's callback page,
        shaped `<code>#<state>` (falls back to an empty state if no `#`).
    :param verifier: The `code_verifier` returned by `start()`.
    :param client: Optional injected httpx client (defaults to
        `platform.http.client()`); tests inject an `httpx.MockTransport`-backed
        client instead of hitting the network.
    :return: The `access_token` from Anthropic's token response.
    :raises LoginError: If the token endpoint responds with a non-2xx status.
        The message is always static — it never includes the code, verifier,
        or any token.
    """
    auth_code, _, state = code.partition("#")
    own = client is None
    http_client = client or make_client()
    try:
        response = http_client.post(
            TOKEN_URL,
            json={
                "grant_type": "authorization_code",
                "code": auth_code,
                "redirect_uri": REDIRECT_URI,
                "client_id": CLIENT_ID,
                "code_verifier": verifier,
                "state": state,
            },
        )
        if response.is_error:
            raise LoginError("failed to exchange the login code for a token")
        return response.json()["access_token"]
    finally:
        if own:
            http_client.close()
