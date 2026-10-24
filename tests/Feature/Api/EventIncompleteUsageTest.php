<?php

use App\Enums\IncompleteUsageReason;
use App\Models\Account;
use App\Models\Boss;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = [];
    fakeRedis($this->store);
    $this->user = User::factory()->create(['hook_token' => hash('sha256', 'tok')]);
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000_000, 'current_hp' => 1_000_000]);
    Account::factory()->create(['organization_uuid' => 'org-xyz']);
    Cache::flush();
});

/**
 * Post a Stop turn, on the org account unless told otherwise.
 *
 * @param  bool  $complete  whether the hook sent model and input/cache tokens
 * @param  ?string  $orgId  the claimed org, null for a personal account
 * @return void
 */
function postOrgStop(bool $complete, ?string $orgId = 'org-xyz'): void
{
    $usage = $complete
        ? ['models' => ['claude-sonnet-5-5' => 500], 'input_tokens' => 10, 'cache_read_input_tokens' => 1000]
        : [];

    test()->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events?provider=claude-code', array_filter([
            'hook_event_name' => 'Stop',
            'session_id' => 'sess-1',
            'tokens' => 500,
            'hook_version' => '7',
            'account_org_id' => $orgId,
            ...$usage,
        ], fn (mixed $value): bool => $value !== null))
        ->assertCreated();
}

it('counts incomplete org turns and resets on a complete one', function () {
    postOrgStop(false);
    postOrgStop(false);

    expect($this->user->fresh()->incomplete_events)->toBe(2)
        ->and($this->user->fresh()->incomplete_reason)->toBe(IncompleteUsageReason::ModifiedHook);

    postOrgStop(true);

    expect($this->user->fresh()->incomplete_events)->toBe(0)
        ->and($this->user->fresh()->incomplete_reason)->toBeNull();
});

it('leaves the counter alone for a personal account turn', function () {
    postOrgStop(false, null);

    expect($this->user->fresh()->incomplete_events)->toBe(0);
});

it('adds no query on users beyond the save ingest already does', function () {
    $userUpdates = 0;
    DB::listen(function ($query) use (&$userUpdates): void {
        if (str_starts_with(strtolower($query->sql), 'update "users"')) {
            $userUpdates++;
        }
    });

    postOrgStop(false);

    expect($userUpdates)->toBe(1);
});
