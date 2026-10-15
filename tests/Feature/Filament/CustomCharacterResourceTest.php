<?php

use App\Events\FighterCharacterChanged;
use App\Filament\Resources\CustomCharacters\Pages\ListCustomCharacters;
use App\Models\CustomCharacter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    Event::fake([FighterCharacterChanged::class]);
});

test('a non-admin cannot open the page', function () {
    Livewire::actingAs(User::factory()->create())->test(ListCustomCharacters::class)->assertForbidden();
});

test('an admin sees every player\'s fighter with its name and owner', function () {
    $admin = User::factory()->admin()->create();
    $one = CustomCharacter::factory()->create(['name' => 'Sir Pixel']);
    $two = CustomCharacter::factory()->create(['name' => 'Moss']);

    Livewire::actingAs($admin)->test(ListCustomCharacters::class)
        ->assertCanSeeTableRecords([$one, $two])
        ->assertTableActionVisible('rename', $one)
        ->assertTableColumnStateSet('name', 'Sir Pixel', $one);
});

test('an admin can rename a fighter with controls removed and announce the change', function () {
    $admin = User::factory()->admin()->create();
    $character = CustomCharacter::factory()->create();

    Livewire::actingAs($admin)->test(ListCustomCharacters::class)
        ->callTableAction('rename', $character, ['name' => "Pixel\u{202E}\n Hero"])
        ->assertHasNoTableActionErrors();

    expect($character->fresh()->name)->toBe('Pixel Hero');
    Event::assertDispatched(FighterCharacterChanged::class, fn ($event) => $event->user->is($character->user));
});

test('an admin can take a fighter down: the player falls back to their roster character and the field drops it live', function () {
    $admin = User::factory()->admin()->create();
    $character = CustomCharacter::factory()->create();
    Storage::disk('public')->put("custom-characters/{$character->user_id}/{$character->version_hash}/idle.png", 'x');

    Livewire::actingAs($admin)->test(ListCustomCharacters::class)
        ->callTableAction('remove', $character)
        ->assertHasNoTableActionErrors();

    expect(CustomCharacter::count())->toBe(0);
    Storage::disk('public')->assertMissing("custom-characters/{$character->user_id}/{$character->version_hash}/idle.png");
    Event::assertDispatched(FighterCharacterChanged::class, fn ($event) => $event->user->is($character->user) && $event->user->customFighterState() === null);
});

test('a role that may view the list but not delete cannot take a fighter down', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole(Role::create(['name' => 'viewer', 'guard_name' => 'web'])->givePermissionTo(Permission::findByName('ViewAny:CustomCharacter')));
    $character = CustomCharacter::factory()->create();

    Livewire::actingAs($viewer)->test(ListCustomCharacters::class)
        ->assertCanSeeTableRecords([$character])
        ->assertTableActionHidden('rename', $character)
        ->assertTableActionHidden('remove', $character);

    expect(CustomCharacter::count())->toBe(1);
});

test('a viewer cannot invoke Rename directly', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole(Role::create(['name' => 'rename-viewer', 'guard_name' => 'web'])->givePermissionTo(Permission::findByName('ViewAny:CustomCharacter')));
    $character = CustomCharacter::factory()->create(['name' => 'Original']);

    expect(fn () => Livewire::actingAs($viewer)->test(ListCustomCharacters::class)
        ->callTableAction('rename', $character, ['name' => 'Unauthorized']))
        ->toThrow(PHPUnit\Framework\ExpectationFailedException::class, 'action with name [rename] is visible');

    expect($character->fresh()->name)->toBe('Original');
});

test('renaming while the owner is busy keeps the old name and shows a notice', function () {
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    $admin = User::factory()->admin()->create();
    $character = CustomCharacter::factory()->create(['name' => 'Before']);
    Cache::lock(App\Support\CacheKeys::customCharacterBuild($character->user_id), 30)->get();

    Livewire::actingAs($admin)->test(ListCustomCharacters::class)
        ->callTableAction('rename', $character, ['name' => 'After'])
        ->assertNotified();

    expect($character->fresh()->name)->toBe('Before');
});

test('taking a fighter down while its owner has a change running is turned away with a message, and nothing is removed', function () {
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    $admin = User::factory()->admin()->create();
    $character = CustomCharacter::factory()->create();
    Cache::lock(App\Support\CacheKeys::customCharacterBuild($character->user_id), 30)->get();

    Livewire::actingAs($admin)->test(ListCustomCharacters::class)
        ->callTableAction('remove', $character)
        ->assertNotified();

    expect(CustomCharacter::count())->toBe(1);
});
