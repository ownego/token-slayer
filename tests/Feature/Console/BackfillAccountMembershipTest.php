<?php

use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('backfills membership and reports the count', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create();
    Event::factory()->for($user)->for($account)->create();

    $this->artisan('account-membership:backfill')
        ->expectsOutputToContain('Materialized 1')
        ->assertSuccessful();

    expect($account->untrackedUsers()->whereKey($user->id)->exists())->toBeTrue();
});
