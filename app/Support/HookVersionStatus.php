<?php

namespace App\Support;

use App\Models\User;

/**
 * Whether a developer's installed hook is behind the one this server serves.
 *
 * Split out of the two components that ask because the answer is not the
 * obvious comparison: `users.hook_version` is null for two opposite
 * populations, and only one of them should be nudged.
 */
final class HookVersionStatus
{
    /**
     * Whether `$user` should be told their hook is out of date.
     *
     * A hook older than v5 never sends `hook_version` at all, so null means
     * either "never installed anything" or "running exactly the build that
     * most needs replacing". `client_version` separates them: every hook ever
     * shipped sends that, so a user who has reported one but no hook version
     * is on an old hook, not an absent one. Comparing on `hook_version` alone
     * left the banner silent for precisely the people it exists for — and
     * they are also the ones who cannot upgrade themselves, since the
     * auto-update mechanism ships in the release they are missing.
     *
     * @param  ?User  $user  the signed-in developer, or null
     * @param  ?string  $latest  the hook version this server serves
     * @return bool
     */
    public static function isOutdated(?User $user, ?string $latest): bool
    {
        if ($user === null || $latest === null) {
            return false;
        }

        if ($user->hook_version === null) {
            return $user->client_version !== null;
        }

        return $user->hook_version !== $latest;
    }
}
