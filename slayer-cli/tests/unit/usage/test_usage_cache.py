from slayer_cli.usage.cache import candidate_for, cache_key
from slayer_cli.usage import cache
from slayer_cli.models.usage_windows import AccountUsage, Window
from slayer_cli.models.account import Account
from slayer_cli.platform.paths import Paths

def test_cache_key_prefers_uuid_org(tmp_path, monkeypatch):
    a = Account(name="x", uuid="u1", org_uuid="o1", email="e@x.com", token="sk-ant-oat01-TESTTOKEN", added_at=1)
    assert cache.cache_key(a) == "u1|o1"
    assert cache.cache_key(Account(name="x", org_uuid="o1", token="sk-ant-oat01-TESTTOKEN", added_at=1)) == "o1"
    assert cache.cache_key(Account(name="x", email="e@x.com", token="sk-ant-oat01-TESTTOKEN", added_at=1)) == "e@x.com"

def test_cache_round_trip(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    c = {"u1|o1": AccountUsage(five_hour=Window(utilization=42.0), polled_at=1)}
    cache.save_cache(p, c)
    loaded = cache.load_cache(p)
    assert loaded["u1|o1"].five_hour.utilization == 42.0
    assert cache.load_cache(Paths("other_ns")) == {}   # missing → empty

def test_candidate_for_key_matches_cache_key():
    a = Account(name="work", uuid="u1", org_uuid="o1", token="sk-ant-oat01-TESTTOKEN", added_at=1)
    c = candidate_for(a)
    assert c.name == "work" and c.key == cache_key(a) == "u1|o1"
