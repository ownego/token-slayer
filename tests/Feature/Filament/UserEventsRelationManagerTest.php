<?php

use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\EventsRelationManager;
use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists this user\'s events newest first', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $account = Account::factory()->create();

    $older = Event::factory()->for($user)->for($account)->create(['created_at' => now()->subDay()]);
    $newer = Event::factory()->for($user)->for($account)->create(['created_at' => now()]);

    Livewire::actingAs($admin)
        ->test(EventsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewUser::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
});
