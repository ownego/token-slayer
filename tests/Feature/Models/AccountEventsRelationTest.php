<?php

use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns only this account\'s events', function () {
    $account = Account::factory()->create();
    $other = Account::factory()->create();
    $user = User::factory()->create();

    Event::factory()->count(2)->for($user)->for($account)->create();
    Event::factory()->for($user)->for($other)->create();

    expect($account->events()->count())->toBe(2);
    expect($account->events->pluck('account_id')->unique()->all())->toBe([$account->id]);
});
