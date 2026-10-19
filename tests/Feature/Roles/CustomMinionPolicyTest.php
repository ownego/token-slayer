<?php

use App\Models\CustomMinion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('lets the owner or a Delete:CustomCharacter role delete', function () {
    $minion = CustomMinion::factory()->create();
    $moderator = User::factory()->create();
    $moderator->assignRole(Role::create(['name' => 'minion-moderator', 'guard_name' => 'web'])->givePermissionTo(Permission::findByName('Delete:CustomCharacter')));

    expect(Gate::forUser($minion->user)->allows('delete', $minion))->toBeTrue()
        ->and(Gate::forUser($moderator)->allows('delete', $minion))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('delete', $minion))->toBeFalse();
});

it('shows the admin list only to a role with ViewAny:CustomCharacter', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole(Role::create(['name' => 'minion-viewer', 'guard_name' => 'web'])->givePermissionTo(Permission::findByName('ViewAny:CustomCharacter')));

    expect(Gate::forUser(User::factory()->create())->allows('viewAny', CustomMinion::class))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('viewAny', CustomMinion::class))->toBeTrue();
});
