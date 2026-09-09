<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\CodexCredential;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('defaults accounts.provider to claude', function (): void {
    $account = Account::create(['email' => 'claude-default@example.com']);

    expect($account->provider)->toBe('claude');
});

it('belongs to an account and casts its columns', function (): void {
    $account = Account::create(['email' => 'codex-owner@example.com', 'provider' => 'codex']);
    $credential = CodexCredential::create([
        'account_id' => $account->id,
        'chatgpt_account_id' => 'acct-1',
        'chatgpt_user_id' => 'user-1',
        'plan_type' => 'pro',
        'codex_access_token' => 'codex-access-fixture',
        'codex_refresh_token' => 'codex-refresh-fixture',
        'codex_expires_at' => now()->addDays(10),
        'status' => AccountStatus::Active,
    ]);

    $raw = DB::table('codex_credentials')->where('id', $credential->id)->first();

    expect($credential->account)->toBeInstanceOf(Account::class)
        ->and($credential->account->id)->toBe($account->id)
        ->and($credential->fresh()->status)->toBe(AccountStatus::Active)
        ->and($credential->fresh()->codex_access_token)->toBe('codex-access-fixture')
        ->and($raw->codex_access_token)->not->toContain('codex-access-fixture');
});

it('exposes a codexCredential relation from Account', function (): void {
    $account = Account::create(['email' => 'relation@example.com', 'provider' => 'codex']);
    CodexCredential::create(['account_id' => $account->id, 'chatgpt_account_id' => 'acct-2']);

    expect($account->codexCredential)->toBeInstanceOf(CodexCredential::class)
        ->and($account->codexCredential->chatgpt_account_id)->toBe('acct-2');
});

it('enforces uniqueness on chatgpt_account_id', function (): void {
    $a = Account::create(['email' => 'a@example.com', 'provider' => 'codex']);
    $b = Account::create(['email' => 'b@example.com', 'provider' => 'codex']);
    CodexCredential::create(['account_id' => $a->id, 'chatgpt_account_id' => 'dup-acct']);

    expect(fn () => CodexCredential::create(['account_id' => $b->id, 'chatgpt_account_id' => 'dup-acct']))
        ->toThrow(QueryException::class);
});
