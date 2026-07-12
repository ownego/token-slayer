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
