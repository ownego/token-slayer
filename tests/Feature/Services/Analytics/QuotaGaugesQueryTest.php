<?php

use App\Enums\AccountPlan;
use App\Enums\CodexPlan;
use App\Enums\Provider;
use App\Models\Account;
use App\Models\CodexCredential;
use App\Services\Analytics\QuotaGaugesQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports the provider and correct plan for a Claude account', function (): void {
    $account = Account::factory()->max5x()->create(['email' => 'claude@example.com']);

    $row = collect(app(QuotaGaugesQuery::class)->get())->firstWhere('account_id', $account->id);

    expect($row['provider'])->toBe(Provider::Claude)
        ->and($row['plan'])->toBe(AccountPlan::Max5x);
});

it('reports the provider and correct CodexPlan for a Codex account, not the Claude default', function (): void {
    $account = Account::factory()->create(['provider' => 'codex', 'email' => 'codex@example.com']);
    CodexCredential::factory()->for($account)->create(['plan_type' => 'team']);

    $row = collect(app(QuotaGaugesQuery::class)->get())->firstWhere('account_id', $account->id);

    expect($row['provider'])->toBe(Provider::Codex)
        ->and($row['plan'])->toBe(CodexPlan::Team);
});
