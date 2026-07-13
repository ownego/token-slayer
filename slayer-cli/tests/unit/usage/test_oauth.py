"""Test OAuth usage fetcher."""
import httpx
from slayer_cli.usage import oauth


def _client(handler):
    return httpx.Client(transport=httpx.MockTransport(handler))


def test_fetch_parses_windows_and_beta_header():
    """Parse windows from response and verify beta+authorization headers."""
    def handler(req):
        assert req.url.path == "/api/oauth/usage"
        assert req.headers["anthropic-beta"] == "oauth-2025-04-20"
        assert req.headers["authorization"] == "Bearer sk-ant-oat01-TESTTOKEN"
        return httpx.Response(200, json={
            "five_hour": {"utilization": 40.0, "resets_at": 1700001000},
            "seven_day": {"utilization": 88.0, "resets_at": 1700600000},
            "seven_day_opus": {"utilization": 100.0, "resets_at": None}})
    u = oauth.fetch_usage("sk-ant-oat01-TESTTOKEN", client=_client(handler))
    assert u.five_hour.utilization == 40.0 and u.five_hour.resets_at == 1700001000
    assert u.seven_day.utilization == 88.0
    assert u.seven_day_opus.utilization == 100.0 and u.seven_day_opus.resets_at is None
    assert u.seven_day_sonnet is None and u.token_expired is False


def test_fetch_401_marks_token_expired():
    """401 response marks token_expired=True."""
    u = oauth.fetch_usage("t", client=_client(lambda r: httpx.Response(401, json={})))
    assert u.token_expired is True


def test_fetch_error_returns_empty_snapshot():
    """Non-200 and non-401 errors return empty (all-None) snapshot."""
    u = oauth.fetch_usage("t", client=_client(lambda r: httpx.Response(500, text="boom")))
    assert u.five_hour is None and u.token_expired is False


def test_fetch_non_json_200_returns_empty():
    """A 200 with a non-JSON body (e.g. a WAF HTML page) → empty snapshot, not a crash."""
    u = oauth.fetch_usage("t", client=_client(lambda r: httpx.Response(200, text="<html>nope</html>")))
    assert u.five_hour is None and u.token_expired is False


def test_fetch_network_error_returns_empty():
    """Network errors (e.g. ConnectError) return empty snapshot, not a crash."""
    def handler(req):
        raise httpx.ConnectError("boom")
    u = oauth.fetch_usage("t", client=_client(handler))
    assert u.five_hour is None and u.token_expired is False


def test_fetch_window_missing_utilization_is_none():
    """A window object present but with no `utilization` key → parsed to None, not a crash."""
    def handler(req):
        return httpx.Response(200, json={"five_hour": {"resets_at": 123}})
    u = oauth.fetch_usage("t", client=_client(handler))
    assert u.five_hour is None
