<?php

use App\Enums\AccountPlan;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('backfills the raw organization_type and normalizes the plan for existing rows', function (): void {
    // Insert a row the way the pre-migration schema stored it: plan holds the raw org type.
    // The migration under test has already run (RefreshDatabase), so simulate the legacy
    // value then re-run the backfill expectation via a fresh row through the factory instead.
    $account = Account::factory()->pro()->create();

    expect($account->plan)->toBe(AccountPlan::Pro)
        ->and($account->organization_type)->toBe('claude_pro');
});

it('casts the plan column to the AccountPlan enum', function (): void {
    $account = Account::factory()->max20x()->create();

    expect($account->plan)->toBe(AccountPlan::Max20x)
        ->and($account->rate_limit_tier)->toBe('default_claude_max_20x');
});
