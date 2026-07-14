"""The account-provider active.json contract the hook reads."""
from __future__ import annotations
from pydantic import BaseModel, field_validator

class ActiveJson(BaseModel):
    org_uuid: str
    email: str | None = None
    uuid: str | None = None
    source: str = "switcher"

    @field_validator("org_uuid")
    @classmethod
    def _org_uuid_nonblank(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("org_uuid must be non-blank (the hook rejects a blank value)")
        return v
