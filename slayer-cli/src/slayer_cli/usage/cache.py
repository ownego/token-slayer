"""On-disk usage cache keyed per seat (accountUuid|orgUuid), so two accounts
sharing an email in different orgs get distinct entries. Atomic 0600."""
from __future__ import annotations
import json
import os
from slayer_cli.models.account import Account
from slayer_cli.models.usage_windows import AccountUsage
from slayer_cli.platform.paths import Paths

_CACHE_FILE = "oauth.json"

def cache_key(account: Account) -> str:
    """Return the usage-cache key for `account`: `uuid|org_uuid`, falling back
    to `org_uuid`, then `email`, then `name`.

    CONTRACT: a strategy Candidate for this account MUST be built with key == cache_key(account); a mismatch makes strategy silently see the account as unpolled.

    :param account: The account slot.
    :return: A stable cache key string.
    """
    if account.uuid and account.org_uuid:
        return f"{account.uuid}|{account.org_uuid}"
    return account.org_uuid or account.email or account.name

def _cache_path(paths: Paths):
    return paths.usage_cache_dir / _CACHE_FILE

def load_cache(paths: Paths) -> dict[str, AccountUsage]:
    """Load the usage cache. Missing/corrupt file → empty dict.

    :param paths: Resolved OS paths.
    :return: Mapping of cache-key → AccountUsage.
    """
    path = _cache_path(paths)
    if not path.is_file():
        return {}
    try:
        raw = json.loads(path.read_text())
    except ValueError:
        return {}
    return {k: AccountUsage.model_validate(v) for k, v in raw.items()}

def save_cache(paths: Paths, cache: dict[str, AccountUsage]) -> None:
    """Write the usage cache atomically at mode 0600 (dir 0700).

    :param paths: Resolved OS paths.
    :param cache: Mapping of cache-key → AccountUsage.
    :return: None
    """
    d = paths.usage_cache_dir
    d.mkdir(parents=True, exist_ok=True)
    os.chmod(d, 0o700)
    payload = json.dumps({k: v.model_dump() for k, v in cache.items()})
    tmp = _cache_path(paths).with_suffix(".tmp")
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as handle:
        handle.write(payload)
    tmp.replace(_cache_path(paths))

def candidate_for(account: Account) -> "Candidate":
    """Build the strategy Candidate for `account`, keyed so a cache lookup hits.

    This is the single construction point for candidates: `key` is always
    `cache_key(account)`, so `strategy` sees the same key the usage cache is
    saved under (a mismatch would silently make the account look unpolled).

    :param account: The account slot.
    :return: A Candidate with name and cache-matched key.
    """
    from slayer_cli.strategy.select import Candidate
    return Candidate(name=account.name, key=cache_key(account))
