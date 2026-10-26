<?php

use App\Events\FighterMinionsChanged;
use App\Filament\Resources\CustomMinions\CustomMinionResource;
use App\Filament\Resources\CustomMinions\Pages\ListCustomMinions;
use App\Models\CustomMinion;
use App\Models\User;
use App\Support\CacheKeys;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
 * A user whose only role grants exactly the given minion permissions.
 *
 * @param  list<string>  $permissions  permission names, which the migrations already created
 * @return User
 */
function adminMinionUserWith(array $permissions): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::create(['name' => 'minion-admin-role', 'guard_name' => 'web'])->givePermissionTo($permissions));

    return $user;
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

it('lists minions with slot, name and tick for a role with ViewAny:CustomMinion', function () {
    $one = CustomMinion::factory()->create(['slot' => 2, 'name' => 'Glim']);
    $two = CustomMinion::factory()->fromFighter()->create(['slot' => 1, 'name' => 'Sprout']);
    $two->user->forceFill(['minion_pool' => ['rev' => 1, 'defaults_off' => [], 'slots_off' => [1]]])->save();

    Livewire::actingAs(adminMinionUserWith(['ViewAny:CustomMinion']))->test(ListCustomMinions::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$one, $two])
        ->assertTableColumnStateSet('user.slack_handle', $one->user->displayHandle(), $one)
        ->assertTableColumnStateSet('slot', 2, $one)
        ->assertTableColumnStateSet('name', 'Glim', $one)
        ->assertTableColumnStateSet('ticked', true, $one)
        ->assertTableColumnStateSet('ticked', false, $two);
});

it('reads the tick from the owner\'s pool, not from the minion row, and adds no query per row', function () {
    $viewer = adminMinionUserWith(['ViewAny:CustomMinion']);
    $unflagged = CustomMinion::factory()->create(['slot' => 1, 'enabled' => false]);
    $countQueries = function () use ($viewer): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        Livewire::actingAs($viewer)->test(ListCustomMinions::class)->assertSuccessful();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $countQueries(); // the first render also loads the permission cache and the panel
    $few = $countQueries();
    $rows = CustomMinion::factory()->count(5)->create(['slot' => 1]);
    $many = $countQueries();

    expect($many)->toBe($few);
    Livewire::actingAs($viewer)->test(ListCustomMinions::class)
        ->assertTableColumnStateSet('ticked', true, $unflagged)
        ->assertTableColumnStateSet('ticked', true, $rows->first());
});

it('hides the list without it', function () {
    Livewire::actingAs(User::factory()->create())->test(ListCustomMinions::class)->assertForbidden();
});

it('hides the list from a role that only has the CustomCharacter permissions', function () {
    $moderator = adminMinionUserWith(['ViewAny:CustomCharacter', 'Delete:CustomCharacter']);

    Livewire::actingAs($moderator)->test(ListCustomMinions::class)->assertForbidden();
});

it('shows the thumbnail from a full URL on the public disk', function () {
    $minion = CustomMinion::factory()->create();

    $component = Livewire::actingAs(adminMinionUserWith(['ViewAny:CustomMinion']))->test(ListCustomMinions::class);
    $column = $component->instance()->getTable()->getColumn('minion')->record($minion);
    $url = $column->getImageUrl($column->getState());

    expect($url)->not->toBeNull()
        ->and(filter_var($url, FILTER_VALIDATE_URL))->not->toBeFalse()
        ->and($url)->toEndWith("/custom-minions/{$minion->user_id}/{$minion->version_hash}/idle.png");
});

it('removes a minion, bumps the owner\'s pool revision and broadcasts', function () {
    $admin = User::factory()->admin()->create();
    $minion = CustomMinion::factory()->create();
    Storage::disk('public')->put("custom-minions/{$minion->user_id}/{$minion->version_hash}/idle.png", 'x');

    Livewire::actingAs($admin)->test(ListCustomMinions::class)
        ->callTableAction('remove', $minion)
        ->assertHasNoTableActionErrors();

    expect(CustomMinion::count())->toBe(0)
        ->and($minion->user->fresh()->minionPoolState()['rev'])->toBe(1);
    Storage::disk('public')->assertMissing("custom-minions/{$minion->user_id}/{$minion->version_hash}/idle.png");
    Event::assertDispatched(FighterMinionsChanged::class, fn ($event) => $event->user->is($minion->user));
});

it('takes the removed minion\'s slot out of the owner\'s unticked slots', function () {
    $admin = User::factory()->admin()->create();
    $minion = CustomMinion::factory()->create(['slot' => 2]);
    $minion->user->forceFill(['minion_pool' => ['rev' => 4, 'defaults_off' => ['demon-a'], 'slots_off' => [2]]])->save();

    Livewire::actingAs($admin)->test(ListCustomMinions::class)->callTableAction('remove', $minion);

    expect($minion->user->fresh()->minion_pool)->toBe(['rev' => 5, 'defaults_off' => ['demon-a'], 'slots_off' => []]);
});

it('lets a role with Delete:CustomMinion remove through the list', function () {
    $moderator = adminMinionUserWith(['ViewAny:CustomMinion', 'Delete:CustomMinion']);
    $minion = CustomMinion::factory()->create();

    Livewire::actingAs($moderator)->test(ListCustomMinions::class)
        ->callTableAction('remove', $minion)
        ->assertHasNoTableActionErrors();

    expect(CustomMinion::count())->toBe(0);
    Event::assertDispatched(FighterMinionsChanged::class);
});

it('does not offer Remove to a role with only ViewAny:CustomMinion', function () {
    $viewer = adminMinionUserWith(['ViewAny:CustomMinion']);
    $minion = CustomMinion::factory()->create();

    Livewire::actingAs($viewer)->test(ListCustomMinions::class)
        ->assertTableActionHidden('remove', $minion);

    expect(CustomMinion::count())->toBe(1);
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

it('shows the busy message when the owner holds the lock', function () {
    $admin = User::factory()->admin()->create();
    $minion = CustomMinion::factory()->create();
    adminMinionHoldLock($minion->user);

    Livewire::actingAs($admin)->test(ListCustomMinions::class)
        ->callTableAction('remove', $minion)
        ->assertNotified();

    expect(CustomMinion::count())->toBe(1)
        ->and($minion->user->fresh()->minionPoolState()['rev'])->toBe(0);
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

it('lists the CustomMinion permissions in Shield as a group that is used', function () {
    Filament::setCurrentPanel('admin');

    $group = FilamentShield::transformResources()[CustomMinionResource::class] ?? null;
    $keys = collect($group['permissions'] ?? [])->pluck('key');

    expect($group)->not->toBeNull()
        ->and($group['model'])->toBe('CustomMinion')
        ->and($keys)->toContain('ViewAny:CustomMinion', 'Delete:CustomMinion')
        ->and(Permission::whereIn('name', ['ViewAny:CustomMinion', 'Delete:CustomMinion'])->count())->toBe(2);
});
