<?php

namespace App\Services\GitHub;

use Illuminate\Support\Facades\Cache;

class CachedLatestVersion
{
    /**
     * Cache key of the latest released CLI version. Global, not per-user —
     * "the latest release" is the same fact for everybody.
     *
     * @var string
     */
    private const string CACHE_KEY = 'github:latest-cli-version';

    /**
     * Sentinel stored when the version could not be resolved. Required because
     * Cache::remember refuses to store null, which would make every profile
     * load pay the full GitHub timeout during an outage.
     *
     * @var string
     */
    private const string UNKNOWN = 'unknown';

    /**
     * Seconds to cache a successfully resolved version. Invalidation is purely
     * time-based: push a release tag and the profile badge catches up within
     * this window. Deliberately short-lived rather than event-invalidated —
     * the badge is allowed to be approximate.
     *
     * @var int
     */
    private const int TTL_SECONDS = 600;

    /**
     * Seconds to cache a resolution failure. Much shorter than the success TTL
     * so a transient GitHub outage costs one slow request rather than one per
     * page load, while recovery is still near-immediate.
     *
     * @var int
     */
    private const int FAILURE_TTL_SECONDS = 60;

    /**
     * Build the cache.
     *
     * @param  ReleaseResolver  $resolver  performs the uncached lookup on a miss
     * @return void
     */
    public function __construct(private readonly ReleaseResolver $resolver) {}

    /**
     * The latest released CLI version for display on the profile badge, or
     * null when it cannot be determined. Intentionally approximate: callers
     * that must be exactly consistent with the served artifact (the install
     * script stamp, the wheel route) use ReleaseResolver directly instead.
     *
     * @return string|null
     */
    public function get(): ?string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached !== null) {
            return $cached === self::UNKNOWN ? null : $cached;
        }

        $version = $this->resolver->latest()['version'] ?? null;

        Cache::put(
            self::CACHE_KEY,
            $version ?? self::UNKNOWN,
            $version !== null ? self::TTL_SECONDS : self::FAILURE_TTL_SECONDS,
        );

        return $version;
    }
}
