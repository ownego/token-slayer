<?php

use App\Models\CustomCharacter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('a player can delete their own fighter', function () {
    $character = CustomCharacter::factory()->create();

    expect(Gate::forUser($character->user)->allows('delete', $character))->toBeTrue();
});

test('a player cannot delete someone else\'s fighter', function () {
    $character = CustomCharacter::factory()->create();

    expect(Gate::forUser(User::factory()->create())->allows('delete', $character))->toBeFalse();
});

test('a role holding Delete:CustomCharacter can take down anyone\'s fighter', function () {
    $character = CustomCharacter::factory()->create();
    $moderator = User::factory()->create();
    $moderator->assignRole(Role::create(['name' => 'moderator', 'guard_name' => 'web'])->givePermissionTo(Permission::findByName('Delete:CustomCharacter')));

    expect(Gate::forUser($moderator)->allows('delete', $character))->toBeTrue();
});

test('only a role with ViewAny:CustomCharacter sees the admin list', function () {
    $plain = User::factory()->create();
    $viewer = User::factory()->create();
    $viewer->assignRole(Role::create(['name' => 'viewer', 'guard_name' => 'web'])->givePermissionTo(Permission::findByName('ViewAny:CustomCharacter')));

    expect(Gate::forUser($plain)->allows('viewAny', CustomCharacter::class))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('viewAny', CustomCharacter::class))->toBeTrue();
});

test('the permissions exist after migrating', function () {
    expect(Permission::whereIn('name', ['ViewAny:CustomCharacter', 'Update:CustomCharacter', 'Delete:CustomCharacter'])->count())->toBe(3);
});

test('a fighter owner cannot update through admin policy without the update permission', function () {
    $character = CustomCharacter::factory()->create();

    expect(Gate::forUser($character->user)->allows('update', $character))->toBeFalse();
});

test('a role with Update:CustomCharacter can rename another player\'s fighter', function () {
    $character = CustomCharacter::factory()->create();
    $moderator = User::factory()->create();
    $moderator->assignRole(Role::create(['name' => 'renamer', 'guard_name' => 'web'])->givePermissionTo(Permission::findByName('Update:CustomCharacter')));

    expect(Gate::forUser($moderator)->allows('update', $character))->toBeTrue();
});
