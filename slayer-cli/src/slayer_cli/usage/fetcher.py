"""Probe Anthropic for per-account quota via the `v1/messages` ratelimit headers.

This is a **billable ~10-token call** — `max_tokens: 1` with a `"hi"` user
message — not the zero-token org-uuid beacon in `auth/beacon.py` (which
returns no `anthropic-ratelimit-*` headers at all). The cost is intentional,
mirroring the ccm shell tool this module ports (`lib/usage.sh`): do not
"optimize" this down to a 0-token call, it would stop returning quota data.
"""
from __future__ import annotations
import httpx
from slayer_cli.models.usage import UsageSnapshot
from slayer_cli.platform.http import request_headers, client as make_client
from slayer_cli.usage.parser import parse_headers

_URL = "https://api.anthropic.com/v1/messages"
_BODY = {
    "model": "claude-haiku-4-5-20251001",
    "max_tokens": 1,
    "messages": [{"role": "user", "content": "hi"}],
}
_TIMEOUT_SECONDS = 10.0


def fetch(token: str, client: httpx.Client | None = None) -> UsageSnapshot:
    """Fetch quota utilization for `token` via the billable probe call.

    :param token: Raw account token, sent only as the Authorization header.
    :param client: Injected httpx client (tests use `MockTransport`); a
        client created here (`client=None`) is closed before returning.
    :return: Parsed `UsageSnapshot`, or an all-`None` snapshot on any HTTP
        error so the TUI degrades gracefully instead of raising.
    """
    own = client is None
    c = client or make_client()
    try:
        response = c.post(
            _URL, headers=request_headers(token), json=_BODY, timeout=_TIMEOUT_SECONDS
        )
        return parse_headers(response.headers)
    except httpx.HTTPError:
        return UsageSnapshot()
    finally:
        if own:
            c.close()
