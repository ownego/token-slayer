<?php

use App\Enums\AccountPlan;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('sets organization_type and rate_limit_tier for a freshly created Pro account', function (): void {
    $account = Account::factory()->pro()->create();

    expect($account->plan)->toBe(AccountPlan::Pro)
        ->and($account->organization_type)->toBe('claude_pro');
});

it('casts the plan column to the AccountPlan enum', function (): void {
    $account = Account::factory()->max20x()->create();

    expect($account->plan)->toBe(AccountPlan::Max20x)
        ->and($account->rate_limit_tier)->toBe('default_claude_max_20x');
});

it('backfills organization_type and resolves plan from the legacy raw value when the migration runs', function (): void {
    // RefreshDatabase has already migrated everything, including this migration. Load the
    // migration file directly and call down()/up() on it, rather than
    // `Artisan::call('migrate:rollback', ['--step' => 1])`: rollback-by-step rolls back
    // whichever migration ran LAST overall, which is only this one when it happens to be the
    // newest file in the repo. Every migration added after it (there are several already)
    // would silently make `--step=1` roll back the wrong migration instead, leaving
    // organization_type still present but never re-backfilled. Targeting this migration file
    // directly keeps the test correct regardless of how many migrations exist before or after it.
    $migration = require database_path('migrations/2026_07_27_000005_add_plan_fields_to_accounts_table.php');
    $migration->down();

    DB::table('accounts')->insert([
        'email' => 'legacy@example.com',
        'plan' => 'claude_max',
    ]);

    $migration->up();

    $raw = DB::table('accounts')->where('email', 'legacy@example.com')->first();

    // Deliberately not asserting Account::find(...)->plan here: this migration
    // backfills the raw accounts.plan/organization_type columns, but the
    // Account model's plan accessor has proxied to claudeCredential->plan since
    // the 2026-09-02 credential split (Account::plan()) — a row inserted
    // straight into accounts with no matching claude_credentials row (as this
    // one is) correctly falls back to AccountPlan::Max20x through the model,
    // regardless of what this migration wrote to the raw column. That fallback
    // is already covered by AccountClaudeCredentialProxyTest.
    expect($raw->organization_type)->toBe('claude_max')
        ->and($raw->rate_limit_tier)->toBeNull()
        ->and($raw->plan)->toBe(AccountPlan::Max->value);
});
