"""A stored Claude account slot."""
from __future__ import annotations
from pydantic import BaseModel, Field

class Account(BaseModel):
    name: str
    email: str | None = None
    org_uuid: str | None = None
    uuid: str | None = None
    plan: str | None = None
    token: str = Field(repr=False)          # raw sk-ant-oat01-… ; never shown
    added_at: int
    last_used: int | None = None
