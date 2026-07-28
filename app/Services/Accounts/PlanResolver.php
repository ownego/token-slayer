<?php

namespace App\Services\Accounts;

use App\Enums\AccountPlan;

/**
 * Derives the normalized {@see AccountPlan} from the `/api/oauth/profile`
 * pair `organization.organization_type` × `organization.rate_limit_tier`.
 *
 * Load-bearing: the tier `default_claude_max_5x` also appears for Team seats,
 * so 5x is only ever inferred when the org type is already `claude_max` — the
 * tier alone is never trusted to mean 5x.
 */
final class PlanResolver
{
    /**
     * Resolve the account's plan from its profile pair. An org type of
     * `claude_max` with an unrecognized/absent tier falls back to the generic
     * {@see AccountPlan::Max} rather than guessing a multiplier; any other
     * unrecognized org type resolves to {@see AccountPlan::Unknown}.
     *
     * @param  ?string  $organizationType  the profile's organization_type
     * @param  ?string  $rateLimitTier  the profile's rate_limit_tier
     * @return AccountPlan
     */
    public function resolve(?string $organizationType, ?string $rateLimitTier): AccountPlan
    {
        return match ($organizationType) {
            'claude_max' => $this->resolveMaxTier($rateLimitTier),
            'claude_pro' => AccountPlan::Pro,
            'claude_free' => AccountPlan::Free,
            default => AccountPlan::Unknown,
        };
    }

    /**
     * Narrow a `claude_max` account to 5x or 20x by its tier suffix, or the
     * generic {@see AccountPlan::Max} when the tier is unrecognized or absent.
     *
     * @param  ?string  $rateLimitTier  the profile's rate_limit_tier
     * @return AccountPlan
     */
    private function resolveMaxTier(?string $rateLimitTier): AccountPlan
    {
        return match (true) {
            $rateLimitTier !== null && str_ends_with($rateLimitTier, 'max_20x') => AccountPlan::Max20x,
            $rateLimitTier !== null && str_ends_with($rateLimitTier, 'max_5x') => AccountPlan::Max5x,
            default => AccountPlan::Max,
        };
    }
}
