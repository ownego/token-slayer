<?php

use App\Enums\MembershipStatus;
use App\Filament\Widgets\TotalActiveUsers;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('counts distinct tracked users across accounts, deduped and excluding untracked/pending', function () {
    $account1 = Account::factory()->create();
    $account2 = Account::factory()->create();
    $trackedOnBoth = User::factory()->create();
    $trackedOnOne = User::factory()->create();
    $untracked = User::factory()->create();
    $pending = User::factory()->create();

    $account1->users()->attach($trackedOnBoth->id, ['status' => MembershipStatus::Tracked->value]);
    $account2->users()->attach($trackedOnBoth->id, ['status' => MembershipStatus::Tracked->value]);
    $account1->users()->attach($trackedOnOne->id, ['status' => MembershipStatus::Tracked->value]);
    $account1->users()->attach($untracked->id, ['status' => MembershipStatus::Untracked->value]);
    $account1->users()->attach($pending->id, ['status' => MembershipStatus::Pending->value]);

    Livewire::test(TotalActiveUsers::class)
        ->assertSuccessful()
        ->assertSee('2'); // trackedOnBoth (counted once) + trackedOnOne
});
