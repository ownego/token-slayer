import httpx
from slayer_cli.auth.beacon import resolve_org_uuid

def _client(handler):
    return httpx.Client(transport=httpx.MockTransport(handler))

def test_beacon_returns_org_header():
    def h(req):
        assert req.url.path == "/v1/messages"
        return httpx.Response(400, headers={"anthropic-organization-id": "0b3d6883"}, json={})
    assert resolve_org_uuid("sk-ant-oat01-TESTTOKEN", _client(h)) == "0b3d6883"

def test_beacon_missing_header_returns_none():
    assert resolve_org_uuid("t", _client(lambda r: httpx.Response(400, json={}))) is None
