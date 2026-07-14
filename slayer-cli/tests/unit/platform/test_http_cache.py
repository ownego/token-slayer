import time
from slayer_cli.platform.cache import TTLCache
from slayer_cli.platform.http import request_headers

def test_headers_have_waf_user_agent_and_auth():
    h = request_headers("sk-ant-oat01-TESTTOKEN")
    assert h["Authorization"] == "Bearer sk-ant-oat01-TESTTOKEN"
    assert "slayer-cli/" in h["User-Agent"]
    assert h["anthropic-version"] == "2023-06-01"

def test_ttlcache_expiry(tmp_path):
    c = TTLCache(tmp_path, ttl=1)
    c.put("oedev", '{"x":1}')
    assert c.get("oedev") == '{"x":1}'
    (tmp_path / "oedev").touch()
    import os; old = time.time() - 5
    os.utime(tmp_path / "oedev", (old, old))
    assert c.get("oedev") is None  # expired
