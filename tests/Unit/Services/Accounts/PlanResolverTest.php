<?php

use App\Enums\AccountPlan;
use App\Services\Accounts\PlanResolver;

it('resolves the plan from the organization_type and rate_limit_tier pair', function (
    ?string $orgType,
    ?string $tier,
    AccountPlan $expected,
): void {
    expect((new PlanResolver)->resolve($orgType, $tier))->toBe($expected);
})->with([
    'max 20x' => ['claude_max', 'default_claude_max_20x', AccountPlan::Max20x],
    'max 5x' => ['claude_max', 'default_claude_max_5x', AccountPlan::Max5x],
    'max unknown tier' => ['claude_max', 'default_claude_ai', AccountPlan::Max],
    'max null tier' => ['claude_max', null, AccountPlan::Max],
    'pro' => ['claude_pro', 'default_claude_ai', AccountPlan::Pro],
    'free' => ['claude_free', 'default_claude_ai', AccountPlan::Free],
    'team org type is not guessed as max 5x' => ['claude_team', 'default_claude_max_5x', AccountPlan::Unknown],
    'unknown org type' => ['something_new', null, AccountPlan::Unknown],
    'all null' => [null, null, AccountPlan::Unknown],
]);
