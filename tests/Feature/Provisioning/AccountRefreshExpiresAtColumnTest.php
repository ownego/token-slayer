<?php

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists and casts oauth_refresh_expires_at as a Carbon instance', function () {
    $account = Account::factory()->create([
        'oauth_refresh_expires_at' => now()->addDays(2),
    ]);

    $reloaded = Account::find($account->id);

    expect($reloaded->oauth_refresh_expires_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

it('allows oauth_refresh_expires_at to be null', function () {
    $account = Account::factory()->create(['oauth_refresh_expires_at' => null]);

    expect(Account::find($account->id)->oauth_refresh_expires_at)->toBeNull();
});
