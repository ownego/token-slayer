<?php

use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows the user\'s basic info', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['name' => 'Target User', 'email' => 'target@example.com']);

    Livewire::actingAs($admin)
        ->test(ViewUser::class, ['record' => $user->getRouteKey()])
        ->assertOk()
        ->assertSee($user->email);
});
