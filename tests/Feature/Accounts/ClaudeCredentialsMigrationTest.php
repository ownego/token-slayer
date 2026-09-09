<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates claude_credentials with one row per existing accounts row, copied from its current column values', function (): void {
    // Target this migration by file, not by "the last migration" (a fragile
    // assumption — see the AccountPlanMigrationTest pre-existing-failure
    // memory note — that breaks the moment ANY later migration is added,
    // which Phase 2's own codex_credentials migration already does).
    $migration = require database_path('migrations/2026_09_02_100001_create_claude_credentials_table.php');
    $migration->down();

    DB::table('accounts')->insert([
        'email' => 'legacy@example.com',
        'plan' => 'max_20x',
        'organization_type' => 'claude_max',
        'rate_limit_tier' => 'default_claude_max_20x',
        'organization_uuid' => 'legacy-org-uuid',
        'account_uuid' => 'legacy-account-uuid',
        'oauth_access_token' => 'encrypted-access-token-fixture',
        'oauth_refresh_token' => 'encrypted-refresh-token-fixture',
        'oauth_expires_at' => now()->subHour(),
        'status' => 'needs_reauth',
        'last_probed_at' => now()->subMinutes(10),
        'probe_error' => 'refresh token expired',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(Schema::hasTable('claude_credentials'))->toBeTrue();

    $accountId = DB::table('accounts')->where('email', 'legacy@example.com')->value('id');
    $credential = DB::table('claude_credentials')->where('account_id', $accountId)->first();

    expect($credential)->not->toBeNull()
        ->and($credential->organization_uuid)->toBe('legacy-org-uuid')
        ->and($credential->account_uuid)->toBe('legacy-account-uuid')
        ->and($credential->oauth_access_token)->toBe('encrypted-access-token-fixture')
        ->and($credential->status)->toBe('needs_reauth')
        ->and($credential->probe_error)->toBe('refresh token expired')
        ->and($credential->oauth_refresh_expires_at)->toBeNull();

    // The old accounts columns are untouched in this deploy.
    $rawAccount = DB::table('accounts')->where('email', 'legacy@example.com')->first();
    expect($rawAccount->organization_uuid)->toBe('legacy-org-uuid');
});

it('backfills every pre-existing accounts row, not just ones created after the migration', function (): void {
    $accountIds = DB::table('accounts')->pluck('id');

    expect(DB::table('claude_credentials')->count())->toBe($accountIds->count());
});
