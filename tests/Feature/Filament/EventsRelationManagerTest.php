<?php

use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\EventsRelationManager;
use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists this account\'s events newest first', function () {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $user = User::factory()->create();

    $older = Event::factory()->for($user)->for($account)->create(['created_at' => now()->subDay()]);
    $newer = Event::factory()->for($user)->for($account)->create(['created_at' => now()]);

    Livewire::actingAs($admin)
        ->test(EventsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
});
