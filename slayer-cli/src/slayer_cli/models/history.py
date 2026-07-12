"""One account-switch history entry."""
from __future__ import annotations
from pydantic import BaseModel, Field

class SwapHistoryEntry(BaseModel):
    ts: int
    from_: str | None = Field(default=None, alias="from")
    to: str
    trigger: str = "manual"
    cwd: str | None = None
    model_config = {"populate_by_name": True}
