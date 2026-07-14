"""Quota fetch/parse/cache: probe Anthropic's ratelimit response headers and
cache the parsed snapshot for `USAGE_TTL_SECONDS` (see `.ai/domain/usage.md`)."""
from __future__ import annotations
