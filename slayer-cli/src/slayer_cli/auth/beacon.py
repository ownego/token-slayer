"""Resolve an account's organization UUID via the zero-cost 400 beacon."""
from __future__ import annotations
import httpx
from slayer_cli.platform.http import request_headers, client as make_client

_BODY = {"model": "claude-haiku-4-5-20251001", "max_tokens": 0, "messages": []}

def resolve_org_uuid(token: str, client: httpx.Client | None = None) -> str | None:
    own = client is None
    c = client or make_client()
    try:
        r = c.post("https://api.anthropic.com/v1/messages", headers=request_headers(token), json=_BODY)
        return r.headers.get("anthropic-organization-id") or None
    except httpx.HTTPError:
        return None
    finally:
        if own:
            c.close()
