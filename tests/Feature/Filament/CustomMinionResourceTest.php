<?php

use App\Events\FighterMinionsChanged;
use App\Filament\Resources\CustomMinions\Pages\ListCustomMinions;
use App\Models\CustomMinion;
use App\Models\User;
use App\Support\CacheKeys;
use Illuminate\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    Event::fake([FighterMinionsChanged::class]);
});

/**
 * A user who may open the minion list, and nothing else.
 *
 * @return User
 */
function adminMinionViewer(): User
{
    $viewer = User::factory()->create();
    $viewer->assignRole(Role::create(['name' => 'minion-list-viewer', 'guard_name' => 'web'])->givePermissionTo(Permission::findByName('ViewAny:CustomCharacter')));

    return $viewer;
}

/**
 * Holds the lock a player's own change would hold, so an admin's removal is turned away.
 *
 * @param  User  $owner  the player whose lock is held
 * @return Lock the held lock
 */
function adminMinionHoldLock(User $owner): Lock
{
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    $held = Cache::lock(CacheKeys::customCharacterBuild($owner->id), 30);
    $held->get();

    return $held;
}

it('lists minions for a role with ViewAny:CustomCharacter', function () {
    $one = CustomMinion::factory()->create();
    $two = CustomMinion::factory()->fromFighter()->create();

    Livewire::actingAs(adminMinionViewer())->test(ListCustomMinions::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$one, $two])
        ->assertTableColumnStateSet('user.slack_handle', $one->user->displayHandle(), $one);
});

it('hides the list without it', function () {
    Livewire::actingAs(User::factory()->create())->test(ListCustomMinions::class)->assertForbidden();
});

it('removes a minion and broadcasts', function () {
    $admin = User::factory()->admin()->create();
    $minion = CustomMinion::factory()->create();
    Storage::disk('public')->put("custom-minions/{$minion->user_id}/{$minion->version_hash}/idle.png", 'x');

    Livewire::actingAs($admin)->test(ListCustomMinions::class)
        ->callTableAction('remove', $minion)
        ->assertHasNoTableActionErrors();

    expect(CustomMinion::count())->toBe(0);
    Storage::disk('public')->assertMissing("custom-minions/{$minion->user_id}/{$minion->version_hash}/idle.png");
    Event::assertDispatched(FighterMinionsChanged::class, fn ($event) => $event->user->is($minion->user));
});

it('shows the busy message when the owner holds the lock', function () {
    $admin = User::factory()->admin()->create();
    $minion = CustomMinion::factory()->create();
    adminMinionHoldLock($minion->user);

    Livewire::actingAs($admin)->test(ListCustomMinions::class)
        ->callTableAction('remove', $minion)
        ->assertNotified();

    expect(CustomMinion::count())->toBe(1);
    Event::assertNotDispatched(FighterMinionsChanged::class);
});
