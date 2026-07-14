import re
from slayer_cli.version import __version__

def test_version_is_semver():
    assert re.match(r"^\d+\.\d+\.\d+$", __version__)
