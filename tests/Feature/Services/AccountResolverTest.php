<?php

use App\Models\Account;
use App\Models\ClaudeCredential;
use App\Models\CodexCredential;
use App\Services\AccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('learns the organization uuid from an email-matched claim', function (): void {
    $account = Account::factory()->create(['email' => 'a@x.com']);

    app(AccountResolver::class)->resolve('org-new', 'a@x.com', 'claude-code');

    expect($account->fresh()->organization_uuid)->toBe('org-new');
});

it('never overwrites a differing organization uuid and logs the conflict', function (): void {
    Log::shouldReceive('warning')->once();
    $account = Account::factory()->withOrganizationUuid('org-a')->create(['email' => 'a@x.com']);

    expect(app(AccountResolver::class)->resolve('org-b', 'a@x.com', 'claude-code'))->toBe($account->id);

    expect($account->fresh()->organization_uuid)->toBe('org-a');
});

it('resolves a known org account email to its id', function () {
    $account = Account::factory()->create(['email' => 'Team@Ownego.com']);

    expect(app(AccountResolver::class)->resolve(null, 'team@ownego.com', 'claude-code'))->toBe($account->id);
});

it('returns null for unknown or missing emails', function () {
    expect(app(AccountResolver::class)->resolve(null, 'stranger@gmail.com', 'claude-code'))->toBeNull()
        ->and(app(AccountResolver::class)->resolve(null, null, 'claude-code'))->toBeNull()
        ->and(app(AccountResolver::class)->resolve(null, '', 'claude-code'))->toBeNull();
});

it('picks up newly created accounts (cache invalidation)', function () {
    $resolver = app(AccountResolver::class);
    expect($resolver->resolve(null, 'late@ownego.com', 'claude-code'))->toBeNull();

    $account = Account::factory()->create(['email' => 'late@ownego.com']);

    expect($resolver->resolve(null, 'late@ownego.com', 'claude-code'))->toBe($account->id);
});

it('resolves by organization uuid before email', function (): void {
    $byOrg = Account::factory()->withOrganizationUuid('org-a')->create(['email' => 'a@x.com']);
    Account::factory()->create(['email' => 'b@x.com']);

    expect(app(AccountResolver::class)->resolve('org-a', 'b@x.com', 'claude-code'))->toBe($byOrg->id);
});

it('falls back to email when the org uuid is unknown', function (): void {
    $account = Account::factory()->create(['email' => 'b@x.com']);

    expect(app(AccountResolver::class)->resolve('org-zzz', 'B@X.com', 'claude-code'))->toBe($account->id);
});

it('invalidates the org map when an account is saved', function (): void {
    $resolver = app(AccountResolver::class);
    expect($resolver->resolve('org-late', null, 'claude-code'))->toBeNull();

    Account::factory()->withOrganizationUuid('org-late')->create();

    expect($resolver->resolve('org-late', null, 'claude-code'))->not->toBeNull();
});

it('resolves a codex event by chatgpt_account_id', function (): void {
    $account = Account::create(['email' => 'chatgpt@company.com', 'provider' => 'codex']);
    CodexCredential::create(['account_id' => $account->id, 'chatgpt_account_id' => 'acct-1']);

    expect(app(AccountResolver::class)->resolve('acct-1', null, 'codex'))->toBe($account->id);
});

it('learns a codex account chatgpt_account_id from an email match', function (): void {
    $account = Account::create(['email' => 'chatgpt@company.com', 'provider' => 'codex']);
    CodexCredential::create(['account_id' => $account->id]);

    $resolved = app(AccountResolver::class)->resolve('acct-new', 'chatgpt@company.com', 'codex');

    expect($resolved)->toBe($account->id)
        ->and($account->fresh()->codexCredential->chatgpt_account_id)->toBe('acct-new');
});

it('a codex event can NEVER write into a claude account organization_uuid, even when emails collide', function (): void {
    $claudeAccount = Account::create(['email' => 'shared@company.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $claudeAccount->id]);

    app(AccountResolver::class)->resolve('acct-x', 'shared@company.com', 'codex');

    expect($claudeAccount->fresh()->organization_uuid)->toBeNull()
        ->and(CodexCredential::where('account_id', $claudeAccount->id)->exists())->toBeFalse();
});

it('a claude event can NEVER resolve against a codex account by email', function (): void {
    $codexAccount = Account::create(['email' => 'shared@company.com', 'provider' => 'codex']);
    CodexCredential::create(['account_id' => $codexAccount->id, 'chatgpt_account_id' => 'acct-1']);

    $resolved = app(AccountResolver::class)->resolve(null, 'shared@company.com', 'claude-code');

    expect($resolved)->toBeNull();
});

it('a claude account chatgpt-id map lookup never resolves a claude-provider account', function (): void {
    $claudeAccount = Account::create(['email' => 'x@company.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $claudeAccount->id, 'organization_uuid' => 'acct-1']);

    expect(app(AccountResolver::class)->resolve('acct-1', null, 'codex'))->toBeNull();
});
