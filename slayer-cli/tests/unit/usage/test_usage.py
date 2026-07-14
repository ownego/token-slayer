import httpx
from slayer_cli.constants import USAGE_TTL_SECONDS
from slayer_cli.models.account import Account
from slayer_cli.platform.paths import Paths
from slayer_cli.usage.parser import parse_headers, pct, bar
from slayer_cli.usage.fetcher import fetch
from slayer_cli.usage import service as usage_service
from slayer_cli.usage.service import UsageService


def test_parse_headers():
    u = parse_headers({"anthropic-ratelimit-unified-5h-utilization": "0.42",
                       "anthropic-ratelimit-unified-5h-status": "allowed",
                       "anthropic-ratelimit-unified-5h-reset": "1720000000"})
    assert u.s5h_util == 0.42 and u.s5h_status == "allowed" and u.s5h_reset == 1720000000
    assert u.s7d_util is None


def test_pct_and_bar():
    assert pct(0.42) == 42
    assert bar(40, 10) == "████░░░░░░"


def test_bar_none_is_fully_empty():
    assert bar(None, 10) == "░░░░░░░░░░"


def test_fetch_reads_response_headers():
    def h(req):
        return httpx.Response(200, headers={"anthropic-ratelimit-unified-5h-utilization": "0.5"}, json={})
    c = httpx.Client(transport=httpx.MockTransport(h))
    assert fetch("sk-ant-oat01-x", c).s5h_util == 0.5


def test_fetch_returns_empty_snapshot_on_http_error():
    def h(req):
        raise httpx.ConnectError("boom", request=req)
    c = httpx.Client(transport=httpx.MockTransport(h))
    snapshot = fetch("sk-ant-oat01-x", c)
    assert snapshot.s5h_util is None and snapshot.s7d_util is None


def _account() -> Account:
    return Account(name="oedev", email=None, org_uuid=None, uuid=None, plan=None,
                    token="sk-ant-oat01-TESTTOKEN", added_at=1, last_used=None)


def test_usage_service_fetches_and_caches(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("CLAUDE_CONFIG_DIR", raising=False)
    paths = Paths("token_slayer")

    calls = []

    def fake_fetch(token, client=None):
        calls.append(token)
        from slayer_cli.models.usage import UsageSnapshot
        return UsageSnapshot(s5h_util=0.3)

    monkeypatch.setattr(usage_service.fetcher, "fetch", fake_fetch)

    svc = UsageService(paths, ttl=USAGE_TTL_SECONDS)
    account = _account()

    first = svc.get(account)
    assert first.s5h_util == 0.3
    assert len(calls) == 1

    second = svc.get(account)
    assert second.s5h_util == 0.3
    assert len(calls) == 1  # cache hit, no re-fetch

    third = svc.get(account, force=True)
    assert third.s5h_util == 0.3
    assert len(calls) == 2  # forced re-fetch
