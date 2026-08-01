<?php

use App\Enums\FighterCharacter;
use App\Events\FighterCharacterChanged;
use App\Livewire\CharacterSelect;
use App\Models\Boss;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Boss::factory()->create(['status' => 'alive', 'number' => 1]);
});

test('equipping a valid character persists it and broadcasts the change', function () {
    Event::fake([FighterCharacterChanged::class]);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CharacterSelect::class)
        ->call('equip', 'werebear');

    expect($user->refresh()->equipped_character)->toBe(FighterCharacter::Werebear);
    Event::assertDispatched(FighterCharacterChanged::class, fn ($e) => $e->user->id === $user->id);
});

test('equipping an unknown character key is rejected', function () {
    Event::fake([FighterCharacterChanged::class]);
    $user = User::factory()->create(['equipped_character' => null]);

    Livewire::actingAs($user)
        ->test(CharacterSelect::class)
        ->call('equip', 'not-a-real-character');

    expect($user->refresh()->equipped_character)->toBeNull();
    Event::assertNotDispatched(FighterCharacterChanged::class);
});

test('characters returns all fifteen fighter character keys', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CharacterSelect::class)
        ->assertSet('equipped', $user->characterForBoss(Boss::first()->id));

    expect((new CharacterSelect)->characters())->toBe(array_column(FighterCharacter::cases(), 'value'));
});

test('a user who has never equipped a character has a null equippedKey even though a deterministic character is shown', function () {
    $user = User::factory()->create(['equipped_character' => null]);

    Livewire::actingAs($user)
        ->test(CharacterSelect::class)
        ->assertSet('equippedKey', null)
        ->assertSet('equipped', $user->characterForBoss(Boss::first()->id));
});

test('equipping a character sets equippedKey to the persisted value', function () {
    $user = User::factory()->create(['equipped_character' => null]);

    Livewire::actingAs($user)
        ->test(CharacterSelect::class)
        ->call('equip', 'werebear')
        ->assertSet('equippedKey', FighterCharacter::Werebear->value);
});
