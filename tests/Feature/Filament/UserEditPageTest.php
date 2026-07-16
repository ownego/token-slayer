<?php

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('hides relation tabs on the edit page but shows them on view', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    // "AccountsRelationManager" is the eagerly-rendered active tab's Livewire
    // component name — asserting the bare word "Accounts" would false-positive
    // against the panel's unrelated "Accounts" resource sidebar nav item.
    // "Events" is the second tab's label (its component body is lazy-loaded,
    // so the label itself is what proves the tab is registered) and is safe
    // to match on directly since no "Events" resource/nav item exists.
    $this->actingAs($admin)
        ->get(UserResource::getUrl('edit', ['record' => $target], panel: 'admin'))
        ->assertOk()
        ->assertDontSee('AccountsRelationManager')
        ->assertDontSee('Events');

    $this->actingAs($admin)
        ->get(UserResource::getUrl('view', ['record' => $target], panel: 'admin'))
        ->assertOk()
        ->assertSee('AccountsRelationManager')
        ->assertSee('Events');
});
