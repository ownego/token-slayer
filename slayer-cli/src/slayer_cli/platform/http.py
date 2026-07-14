"""HTTP helpers: WAF-safe headers + a shared client for Anthropic calls."""
from __future__ import annotations
import httpx
from slayer_cli.version import __version__

USER_AGENT = f"slayer-cli/{__version__} (external, cli)"

def request_headers(token: str) -> dict[str, str]:
    return {
        "Authorization": f"Bearer {token}",
        "anthropic-version": "2023-06-01",
        "content-type": "application/json",
        "User-Agent": USER_AGENT,
    }

def client() -> httpx.Client:
    return httpx.Client(timeout=6.0, headers={"User-Agent": USER_AGENT})
