<?php

namespace App\Services\Accounts;

use App\Enums\AccountPlan;
use App\Enums\CodexPlan;
use App\Enums\Provider;
use App\Models\Account;

/**
 * Resolves the plan badge value to render for an account, of either
 * provider: {@see AccountPlan} for Claude (already stored, resolved by
 * {@see PlanResolver}), {@see CodexPlan} for Codex (derived on read from
 * `codex_credentials.plan_type` — never persisted, see {@see CodexPlan}'s
 * own docblock for why the two providers' plan concepts stay separate
 * enums). Centralizes the one branch every plan-badge-rendering call site
 * (the accounts table, the fleet quota gauge cards) would otherwise repeat.
 */
final class PlanBadgeResolver
{
    /**
     * @param  Account  $account  the account whose provider selects which plan enum to resolve
     * @return AccountPlan|CodexPlan|null the plan badge value, or null when a Codex account's plan_type is absent/unrecognized
     */
    public function for(Account $account): AccountPlan|CodexPlan|null
    {
        return $account->provider === Provider::Codex
            ? CodexPlan::tryFrom($account->codexCredential?->plan_type ?? '')
            : $account->plan;
    }
}
