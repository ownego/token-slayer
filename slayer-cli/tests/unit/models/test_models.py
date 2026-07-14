import json
import pytest
from pydantic import ValidationError
from slayer_cli.models.account import Account
from slayer_cli.models.provider import ActiveJson
from slayer_cli.models.usage import UsageSnapshot

def test_active_json_requires_nonblank_org_uuid():
    with pytest.raises(ValidationError):
        ActiveJson(org_uuid="")
    aj = ActiveJson(org_uuid="0b3d6883", email="a@b.com")
    assert aj.model_dump() == {"org_uuid": "0b3d6883", "email": "a@b.com", "uuid": None, "source": "switcher"}

def test_account_repr_hides_token():
    acc = Account(name="oedev", email=None, org_uuid="0b3d6883", plan=None,
                  token="sk-ant-oat01-TESTTOKEN", added_at=1, last_used=None)
    assert "TESTTOKEN" not in repr(acc)

def test_usage_snapshot_from_partial():
    u = UsageSnapshot(s5h_util=0.42, s5h_status="allowed", s5h_reset=1720000000,
                      s7d_util=None, s7d_reset=None)
    assert u.s5h_util == 0.42 and u.s7d_util is None

def test_account_carries_full_grant_and_alias():
    acc = Account(
        name="work", email="a@b.com", org_uuid="org-1", uuid="u-1", plan="max",
        token="sk-ant-oat01-TESTTOKEN", added_at=1700000000, last_used=None,
        alias="w", refresh_token="sk-ant-ort01-TESTREFRESH",
        expires_at=1_800_000_000_000, oauth_account={"emailAddress": "a@b.com"},
    )
    assert acc.alias == "w"
    assert acc.refresh_token == "sk-ant-ort01-TESTREFRESH"
    assert acc.expires_at == 1_800_000_000_000
    assert acc.oauth_account == {"emailAddress": "a@b.com"}
    # Secrets are hidden from repr.
    assert "TESTREFRESH" not in repr(acc) and "TESTTOKEN" not in repr(acc)
    # Round-trips through JSON.
    assert Account.model_validate_json(acc.model_dump_json()).refresh_token == "sk-ant-ort01-TESTREFRESH"

def test_legacy_slot_json_without_new_fields_still_parses():
    legacy = json.dumps({
        "name": "old", "email": "o@b.com", "org_uuid": None, "uuid": None,
        "plan": None, "token": "sk-ant-oat01-TESTTOKEN", "added_at": 1, "last_used": None,
    })
    acc = Account.model_validate_json(legacy)
    assert (
        acc.alias is None
        and acc.refresh_token is None
        and acc.expires_at is None
        and acc.oauth_account is None
    )
