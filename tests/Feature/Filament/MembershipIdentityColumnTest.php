<?php

use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\EventsRelationManager;
use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Members-tab identity rendering (tracked + untracked, same shared column) is
// covered by tests/Feature/Filament/MembersRelationManagerTest.php.

it('renders the developer handle even when slack_handle is null (events tab)', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $dev = User::factory()->create(['name' => 'Tung Ot', 'slack_handle' => null, 'display_name' => null]);
    Event::factory()->for($dev)->for($account)->create();

    Livewire::actingAs($admin)
        ->test(EventsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->assertSee('Tung Ot');
});
