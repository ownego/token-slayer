<?php

use App\Enums\AccountPlan;
use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\ClaudeCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists a single accessor write to claude_credentials on save', function (): void {
    $account = Account::factory()->create(['email' => 'writer@example.com']);
    $account->organization_uuid = 'written-org-uuid';
    $account->save();

    expect(ClaudeCredential::where('account_id', $account->id)->first()?->organization_uuid)->toBe('written-org-uuid')
        ->and($account->fresh()->organization_uuid)->toBe('written-org-uuid');
});

it('persists multiple accessor writes made before one save to the same credential row', function (): void {
    $account = Account::factory()->create();
    $account->organization_uuid = 'multi-org-uuid';
    $account->oauth_access_token = 'sk-ant-oat01-multi';
    $account->status = AccountStatus::NeedsReauth;
    $account->save();

    expect(ClaudeCredential::where('account_id', $account->id)->count())->toBe(1);

    $credential = $account->fresh()->claudeCredential;
    expect($credential->organization_uuid)->toBe('multi-org-uuid')
        ->and($credential->oauth_access_token)->toBe('sk-ant-oat01-multi')
        ->and($credential->status)->toBe(AccountStatus::NeedsReauth);
});

it('does not create a credential row when a save touches no claude_* accessor', function (): void {
    $account = new Account(['email' => 'untouched@example.com']);
    $account->save();

    expect(ClaudeCredential::where('account_id', $account->id)->exists())->toBeFalse();
});

it('defaults status to Active and plan to Max20x when no credential row exists yet', function (): void {
    $account = new Account(['email' => 'defaults@example.com']);
    $account->save();

    expect($account->status)->toBe(AccountStatus::Active)
        ->and($account->plan)->toBe(AccountPlan::Max20x)
        ->and($account->organization_uuid)->toBeNull();
});

it('scopes probeable accounts through the claude_credentials relation', function (): void {
    $probeable = Account::factory()->connected()->create();
    $disabled = Account::factory()->connected()->create();
    $disabled->status = AccountStatus::Disabled;
    $disabled->save();
    $noRefreshToken = Account::factory()->create(); // never connected — no oauth_refresh_token

    $ids = Account::probeable()->pluck('id');

    expect($ids)->toContain($probeable->id)
        ->and($ids)->not->toContain($disabled->id)
        ->and($ids)->not->toContain($noRefreshToken->id);
});
