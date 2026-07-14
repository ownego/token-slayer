"""Parsed quota snapshot (5h / 7d utilization)."""
from __future__ import annotations
from pydantic import BaseModel

class UsageSnapshot(BaseModel):
    s5h_util: float | None = None
    s5h_status: str | None = None
    s5h_reset: int | None = None
    s7d_util: float | None = None
    s7d_reset: int | None = None
