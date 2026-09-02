<?php

use App\Models\Account;
use App\Models\ClaudeCredential;
use App\Models\CodexCredential;
use App\Services\Attribution\ExpiringAccountsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes a claude account whose refresh token expires within 3 days', function (): void {
    $account = Account::create(['email' => 'soon@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(2)]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    expect(collect($rows)->pluck('account_id'))->toContain($account->id);
    $row = collect($rows)->firstWhere('account_id', $account->id);
    expect($row['provider'])->toBe('claude')
        ->and($row['deadline'])->not->toBeNull();
});

it('excludes a claude account whose refresh token does not expire soon', function (): void {
    $account = Account::create(['email' => 'safe@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(20)]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    expect(collect($rows)->pluck('account_id'))->not->toContain($account->id);
});

it('excludes a claude account with no refresh_expires_at at all', function (): void {
    $account = Account::create(['email' => 'unknown@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    expect(collect($rows)->pluck('account_id'))->not->toContain($account->id);
});

it('includes a codex account whose earliest_refresh_at has passed', function (): void {
    $account = Account::create(['email' => 'codex-stale@example.com', 'provider' => 'codex']);
    CodexCredential::create(['account_id' => $account->id, 'earliest_refresh_at' => now()->subHour()]);

    $rows = app(ExpiringAccountsQuery::class)->get();
    $row = collect($rows)->firstWhere('account_id', $account->id);

    expect($row)->not->toBeNull()
        ->and($row['provider'])->toBe('codex')
        ->and($row['deadline'])->toBeNull();
});

it('includes a codex account with no earliest_refresh_at whose last_refreshed_at is over 8 days old', function (): void {
    $account = Account::create(['email' => 'codex-old@example.com', 'provider' => 'codex']);
    CodexCredential::create(['account_id' => $account->id, 'last_refreshed_at' => now()->subDays(9)]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    expect(collect($rows)->pluck('account_id'))->toContain($account->id);
});

it('excludes a codex account refreshed recently with no earliest_refresh_at', function (): void {
    $account = Account::create(['email' => 'codex-fresh@example.com', 'provider' => 'codex']);
    CodexCredential::create(['account_id' => $account->id, 'last_refreshed_at' => now()->subDays(2)]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    expect(collect($rows)->pluck('account_id'))->not->toContain($account->id);
});
