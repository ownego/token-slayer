<?php

use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows a Claude account under the two windows it reports', function () {
    $account = Account::factory()->create(['provider' => 'claude']);
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => 43, 'util_7d' => 28, 'raw' => [], 'created_at' => now(),
    ]);

    expect(AccountResource::usageWindows($account->fresh()))->toBe([
        ['label' => '5h', 'percent' => 43],
        ['label' => '7d', 'percent' => 28],
    ]);
});

it('shows a Codex account under the window it actually reports', function () {
    // Its 30-day cap used to be filed into util_5h and shown under a "5h"
    // header. Correcting that emptied both columns, which traded a wrongly
    // named number for no number at all — the column has to carry the real
    // window instead.
    $account = Account::factory()->create(['provider' => 'codex']);
    AccountUsageSnapshot::factory()->for($account)->create([
        'util_5h' => null, 'util_7d' => null,
        'raw' => ['rate_limit' => [
            'primary_window' => ['used_percent' => 4, 'limit_window_seconds' => 2592000],
            'secondary_window' => null,
        ]],
        'created_at' => now(),
    ]);

    expect(AccountResource::usageWindows($account->fresh()))->toBe([
        ['label' => '30D', 'percent' => 4],
    ]);
});

it('reports no windows for an account that has never been probed', function () {
    $account = Account::factory()->create(['provider' => 'claude']);

    expect(AccountResource::usageWindows($account->fresh()))->toBe([]);
});
