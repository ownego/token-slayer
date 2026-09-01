<?php

use App\Enums\AccountPlan;
use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\ClaudeCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('belongs to an account', function (): void {
    // Account::create (not the factory) so no claude_credentials row is
    // auto-created by the accessor's persistence hook — this test wants
    // full control over the credential row it creates.
    $account = Account::create(['email' => 'owner@example.com']);
    $credential = ClaudeCredential::create([
        'account_id' => $account->id,
        'organization_uuid' => 'org-uuid-1',
    ]);

    expect($credential->account)->toBeInstanceOf(Account::class)
        ->and($credential->account->id)->toBe($account->id);
});

it('casts status and plan to their enums, and encrypts oauth tokens at rest', function (): void {
    $account = Account::create(['email' => 'casts@example.com']);
    $credential = ClaudeCredential::create([
        'account_id' => $account->id,
        'status' => AccountStatus::NeedsReauth,
        'plan' => AccountPlan::Pro,
        'oauth_access_token' => 'sk-ant-oat01-fixture',
    ]);

    $raw = DB::table('claude_credentials')->where('id', $credential->id)->first();

    expect($credential->fresh()->status)->toBe(AccountStatus::NeedsReauth)
        ->and($credential->fresh()->plan)->toBe(AccountPlan::Pro)
        ->and($credential->fresh()->oauth_access_token)->toBe('sk-ant-oat01-fixture')
        ->and($raw->oauth_access_token)->not->toContain('sk-ant-oat01-fixture');
});

it('exposes a claudeCredential relation from Account', function (): void {
    $account = Account::create(['email' => 'relation@example.com']);
    ClaudeCredential::create(['account_id' => $account->id, 'organization_uuid' => 'org-uuid-2']);

    expect($account->claudeCredential)->toBeInstanceOf(ClaudeCredential::class)
        ->and($account->claudeCredential->organization_uuid)->toBe('org-uuid-2');
});
