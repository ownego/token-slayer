import pytest


@pytest.fixture
def anyio_backend():
    """Pin anyio's pytest plugin to asyncio only (no trio dependency)."""
    return "asyncio"
