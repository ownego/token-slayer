import time, httpx, pytest
from slayer_cli.credstore import refresh

def test_is_expired():
    now_ms = int(time.time() * 1000)
    assert refresh.is_expired({}) is True                                  # no expiresAt
    assert refresh.is_expired({"expiresAt": now_ms + 60_000}) is True       # within 5-min buffer
    assert refresh.is_expired({"expiresAt": now_ms + 3_600_000}) is False   # 1h out

def test_refresh_grant_patches_and_preserves():
    def handler(req):
        assert req.url.path == "/v1/oauth/token"
        return httpx.Response(200, json={
            "access_token": "sk-ant-oat01-NEW", "refresh_token": "sk-ant-ort01-NEW", "expires_in": 28800})
    client = httpx.Client(transport=httpx.MockTransport(handler))
    block = {"accessToken": "sk-ant-oat01-OLD", "refreshToken": "sk-ant-ort01-OLD",
             "expiresAt": 1, "scopes": ["user:inference"], "subscriptionType": "max"}
    out = refresh.refresh_grant(block, client=client)
    assert out["accessToken"] == "sk-ant-oat01-NEW"
    assert out["refreshToken"] == "sk-ant-ort01-NEW"
    assert out["expiresAt"] > int(time.time() * 1000)
    assert out["scopes"] == ["user:inference"] and out["subscriptionType"] == "max"  # preserved

def test_refresh_grant_raises_on_non_200():
    client = httpx.Client(transport=httpx.MockTransport(lambda r: httpx.Response(400, json={"error": "invalid_grant"})))
    with pytest.raises(refresh.RefreshError):
        refresh.refresh_grant({"refreshToken": "sk-ant-ort01-OLD"}, client=client)

def test_refresh_grant_raises_on_missing_refresh_token():
    with pytest.raises(refresh.RefreshError):
        refresh.refresh_grant({})  # no refreshToken

def test_refresh_grant_raises_on_missing_access_token_in_response():
    def handler(req):
        return httpx.Response(200, json={"refresh_token": "sk-ant-ort01-NEW", "expires_in": 28800})
    client = httpx.Client(transport=httpx.MockTransport(handler))
    with pytest.raises(refresh.RefreshError):
        refresh.refresh_grant({"refreshToken": "sk-ant-ort01-OLD"}, client=client)
