<?php

use App\Enums\AccountPlan;
use App\Enums\CodexPlan;
use App\Enums\Provider;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
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

it('reports when the account was last probed, or null when never', function (): void {
    $probed = Account::factory()->create();
    $never = Account::factory()->create();
    AccountUsageSnapshot::factory()->for($probed)->create(['created_at' => now()->subMinutes(4)]);

    $rows = collect(app(QuotaGaugesQuery::class)->get())->keyBy('account_id');

    expect($rows[$probed->id]['probed_at']->diffInMinutes(now()))->toBeGreaterThanOrEqual(3.9)
        ->and($rows[$never->id]['probed_at'])->toBeNull();
});

it('labels the gauge with the account name, falling back to the email while the name is blank or the default organization name', function (?string $name, string $label): void {
    $account = Account::factory()->create(['email' => 'clone@example.com', 'name' => $name]);

    $row = collect(app(QuotaGaugesQuery::class)->get())->firstWhere('account_id', $account->id);

    expect($row['label'])->toBe($label)
        ->and($row['email'])->toBe('clone@example.com');
})->with([
    'custom name' => ['OE Clone', 'OE Clone'],
    'default organization name' => ["clone@example.com's Organization", 'clone@example.com'],
    'no name' => [null, 'clone@example.com'],
]);
