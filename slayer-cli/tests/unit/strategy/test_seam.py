from slayer_cli.usage.cache import candidate_for, save_cache, cache_key
from slayer_cli.models.account import Account
from slayer_cli.models.usage_windows import AccountUsage, Window, Thresholds
from slayer_cli.strategy.select import pick_next
from slayer_cli.platform.paths import Paths


def test_strategy_sees_usage_saved_under_candidate_key(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    p = Paths("token_slayer")
    a = Account(name="a", uuid="ua", org_uuid="oa", token="sk-ant-oat01-TESTTOKEN", added_at=1)
    b = Account(name="b", uuid="ub", org_uuid="ob", token="sk-ant-oat01-TESTTOKEN", added_at=1)
    c = Account(name="c", uuid="uc", org_uuid="oc", token="sk-ant-oat01-TESTTOKEN", added_at=1)

    # b has high 7d usage (90%), c has low (10%).
    # balanced strategy picks the LOWEST 7d among eligible accounts.
    # Correct keys → b=90, c=10 → picks c.
    # Mismatched keys → b=0.0, c=0.0 (tie) → picks first in input order (b).
    # This test discriminates: correct behavior picks c, broken keys would pick b.
    cache = {
        cache_key(b): AccountUsage(five_hour=Window(utilization=5.0),
                                   seven_day=Window(utilization=90.0), polled_at=1),
        cache_key(c): AccountUsage(five_hour=Window(utilization=2.0),
                                   seven_day=Window(utilization=10.0), polled_at=1)
    }
    pick = pick_next("balanced", [], [candidate_for(b), candidate_for(c)], candidate_for(a),
                     cache, Thresholds())
    # If candidate_for's key did NOT match cache_key, both b and c would appear as util 0.0,
    # resulting in a tie and picking the first in order (b). With correct keys, c's 10% is
    # lower than b's 90%, so balanced strategy picks c.
    assert pick.name == "c"
