"""Cache-aware usage orchestration: serve a fresh `TTLCache` hit, otherwise
probe and re-cache. See `.ai/domain/usage.md` for the fetch/cache/display pipeline."""
from __future__ import annotations
from slayer_cli import constants
from slayer_cli.models.account import Account
from slayer_cli.models.usage import UsageSnapshot
from slayer_cli.platform.cache import TTLCache
from slayer_cli.platform.paths import Paths
from slayer_cli.usage import fetcher

__all__ = ["UsageService"]


class UsageService:
    """Fetches an account's quota, caching parsed snapshots for `ttl` seconds."""

    def __init__(self, paths: Paths, ttl: int = constants.USAGE_TTL_SECONDS) -> None:
        """
        :param paths: Resolved OS paths for this namespace.
        :param ttl: Cache freshness window, in seconds.
        """
        self._cache = TTLCache(paths.usage_cache_dir, ttl)

    def get(self, account: Account, force: bool = False) -> UsageSnapshot:
        """Return `account`'s quota snapshot, from cache when fresh or a live probe otherwise.

        The cache holds only the parsed snapshot (utilization/reset/status
        JSON) keyed by account name — never the token.

        :param account: Account slot to fetch quota for.
        :param force: When `True`, skip the cache and always probe.
        :return: Parsed `UsageSnapshot`.
        """
        if not force:
            cached = self._cache.get(account.name)
            if cached is not None:
                return UsageSnapshot.model_validate_json(cached)
        snapshot = fetcher.fetch(account.token)
        self._cache.put(account.name, snapshot.model_dump_json())
        return snapshot
