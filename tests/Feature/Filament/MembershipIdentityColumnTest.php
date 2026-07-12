<?php

use App\Enums\MembershipStatus;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Accounts\RelationManagers\UntrackedContributorsRelationManager;
use App\Filament\Resources\Accounts\RelationManagers\UsersRelationManager;
use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders a member identity even when slack_handle is null (tracking tab)', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->create();
    $member = User::factory()->create(['name' => 'Tung Ot', 'slack_handle' => null, 'display_name' => null]);
    $account->users()->attach($member, ['status' => MembershipStatus::Tracked->value]);

    Livewire::actingAs($admin)
        ->test(UsersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->assertSee('Tung Ot');
});

it('renders a contributor identity even when slack_handle is null (untracked tab)', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->create();
    $contributor = User::factory()->create(['name' => 'Tung Ot', 'slack_handle' => null, 'display_name' => null]);
    $account->users()->attach($contributor, ['status' => MembershipStatus::Untracked->value]);
    Event::factory()->for($contributor)->for($account)->create();

    Livewire::actingAs($admin)
        ->test(UntrackedContributorsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->assertSee('Tung Ot');
});

it('renders the developer handle even when slack_handle is null (events tab)', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $account = Account::factory()->create();
    $dev = User::factory()->create(['name' => 'Tung Ot', 'slack_handle' => null, 'display_name' => null]);
    Event::factory()->for($dev)->for($account)->create();

    Livewire::actingAs($admin)
        ->test(EventsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->assertSee('Tung Ot');
});
