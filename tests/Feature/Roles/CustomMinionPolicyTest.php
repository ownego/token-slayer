<?php

use App\Models\CustomMinion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * A user holding a role with exactly the given permissions.
 *
 * @param  list<string>  $permissions  permission names, which the migrations already created
 * @return User
 */
function minionPolicyUserWith(array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::create(['name' => 'minion-role-'.implode('-', $permissions), 'guard_name' => 'web']);
    $role->givePermissionTo(array_map(fn (string $name): Permission => Permission::findByName($name), $permissions));
    $user->assignRole($role);

    return $user;
}

it('lets the owner remove their own minion', function () {
    $minion = CustomMinion::factory()->create();

    expect(Gate::forUser($minion->user)->allows('delete', $minion))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('delete', $minion))->toBeFalse();
});

it('lets a role with Delete:CustomMinion remove a minion', function () {
    $minion = CustomMinion::factory()->create();
    $moderator = minionPolicyUserWith(['Delete:CustomMinion']);

    expect(Gate::forUser($moderator)->allows('delete', $minion))->toBeTrue();
});

it('refuses removal to a role with only ViewAny:CustomMinion', function () {
    $minion = CustomMinion::factory()->create();
    $viewer = minionPolicyUserWith(['ViewAny:CustomMinion']);

    expect(Gate::forUser($viewer)->allows('viewAny', CustomMinion::class))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('delete', $minion))->toBeFalse();
});

it('no longer accepts the CustomCharacter permissions for minions', function () {
    $minion = CustomMinion::factory()->create();
    $fighterModerator = minionPolicyUserWith(['ViewAny:CustomCharacter', 'Update:CustomCharacter', 'Delete:CustomCharacter']);

    expect(Gate::forUser($fighterModerator)->allows('viewAny', CustomMinion::class))->toBeFalse()
        ->and(Gate::forUser($fighterModerator)->allows('delete', $minion))->toBeFalse();
});
