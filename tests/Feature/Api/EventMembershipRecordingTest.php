<?php

use App\Models\Account;
use App\Models\Boss;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['hook_token' => hash('sha256', 'tok')]);
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000_000, 'current_hp' => 1_000_000]);
    Cache::flush();
});

it('materializes an untracked membership row when a stop event resolves an account', function () {
    $account = Account::factory()->create(['organization_uuid' => 'org-xyz']);

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop',
            'tokens' => 350,
            'account_org_id' => 'org-xyz',
        ])
        ->assertCreated();

    expect($account->untrackedUsers()->whereKey($this->user->id)->exists())->toBeTrue();
});

it('does not create a membership row when the account cannot be resolved', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop',
            'tokens' => 350,
        ])
        ->assertCreated();

    expect($this->user->accounts()->exists())->toBeFalse();
});
