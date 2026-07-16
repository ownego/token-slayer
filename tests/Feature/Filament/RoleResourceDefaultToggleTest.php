<?php

use App\Filament\Resources\Shield\Pages\CreateRole;
use App\Filament\Resources\Shield\Pages\EditRole;
use App\Filament\Resources\Shield\RoleResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('persists is_default true through the create form save path', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(CreateRole::class)
        ->fillForm(['name' => 'viewer', 'guard_name' => 'web', 'is_default' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect((bool) Role::where('name', 'viewer')->sole()->is_default)->toBeTrue();
});

it('persists is_default true through the edit form save path', function () {
    $admin = User::factory()->admin()->create();
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

    Livewire::actingAs($admin)
        ->test(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm(['is_default' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((bool) $role->fresh()->is_default)->toBeTrue();
});

it('shows the default toggle and syncs defaults on save', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();

    $role = Role::create(['name' => 'viewer', 'guard_name' => 'web']);

    $this->actingAs($admin)
        ->get(RoleResource::getUrl('edit', ['record' => $role], panel: 'admin'))
        ->assertOk()
        ->assertSee('Assign to every user');

    // Marking the role default must itself trigger the sync (RoleObserver).
    // `forceFill` bypasses the process-static guardableColumns cache poisoned
    // by an earlier migration (see KB §21) while still firing `saved()` with
    // `wasChanged('is_default')` true, exactly as Filament's own form save does.
    $role->forceFill(['is_default' => true])->save();

    expect($other->fresh()->hasRole('viewer'))->toBeTrue();
});

it('does not sync when a role is saved without becoming default', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $other = User::factory()->create();

    $role->update(['name' => 'editor-renamed']);

    expect($other->fresh()->roles)->toBeEmpty();
});
