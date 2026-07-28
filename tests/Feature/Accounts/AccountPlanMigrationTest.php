<?php

use App\Enums\AccountPlan;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
    // RefreshDatabase has already migrated everything, including this migration. Roll this
    // one migration back to restore the pre-migration shape (plan holds the raw org type,
    // no organization_type/rate_limit_tier columns), insert a legacy-shaped row directly via
    // the query builder (bypassing the Account model's enum cast, which would reject the raw
    // string), then re-run the migration and inspect the backfilled result.
    Artisan::call('migrate:rollback', ['--step' => 1]);

    DB::table('accounts')->insert([
        'email' => 'legacy@example.com',
        'plan' => 'claude_max',
    ]);

    Artisan::call('migrate');

    $raw = DB::table('accounts')->where('email', 'legacy@example.com')->first();

    expect($raw->organization_type)->toBe('claude_max')
        ->and($raw->rate_limit_tier)->toBeNull()
        ->and($raw->plan)->toBe(AccountPlan::Max->value);

    expect(Account::where('email', 'legacy@example.com')->first()->plan)->toBe(AccountPlan::Max);
});
