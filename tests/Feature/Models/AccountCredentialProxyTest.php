<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\CodexCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('a Codex account with a codexCredential reads its real status, not the Claude default', function (): void {
    $account = Account::factory()->create(['provider' => 'codex']);
    CodexCredential::factory()->for($account)->create(['status' => AccountStatus::Disabled]);

    expect($account->fresh()->status)->toBe(AccountStatus::Disabled);
});

it('a bare Codex account with no codexCredential yet defaults to NeedsReauth, not Active', function (): void {
    $account = Account::factory()->create(['provider' => 'codex']);

    expect($account->status)->toBe(AccountStatus::NeedsReauth);
});

it('a Claude account keeps defaulting to Active with no credential row', function (): void {
    $account = new Account(['provider' => 'claude', 'email' => 'bare@example.com']);

    expect($account->status)->toBe(AccountStatus::Active);
});

it('lastProbedAt and probeError proxy to codexCredential for a Codex account', function (): void {
    $account = Account::factory()->create(['provider' => 'codex']);
    $probedAt = now();
    CodexCredential::factory()->for($account)->create([
        'last_probed_at' => $probedAt,
        'probe_error' => 'usage probe failed: rate_limited',
    ]);
    $account->refresh();

    expect($account->last_probed_at->timestamp)->toBe($probedAt->timestamp)
        ->and($account->probe_error)->toBe('usage probe failed: rate_limited');
});
