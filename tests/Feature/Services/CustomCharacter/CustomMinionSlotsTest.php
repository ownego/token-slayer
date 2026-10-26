<?php

use App\Models\CustomMinion;
use App\Models\User;
use App\Services\CustomCharacter\CustomMinionSlots;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('finds the lowest free slot', function () {
    $user = User::factory()->create();
    CustomMinion::factory()->for($user)->create(['slot' => 1]);
    CustomMinion::factory()->for($user)->create(['slot' => 3]);

    expect(app(CustomMinionSlots::class)->firstFree($user->fresh()))->toBe(2);
});

it('starts at slot 1 for a player with no minions', function () {
    expect(app(CustomMinionSlots::class)->firstFree(User::factory()->create()))->toBe(1);
});

it('is full at minions_max', function (int $held, bool $full) {
    $user = User::factory()->create();
    CustomMinion::factory()->count($held)->for($user)
        ->sequence(fn ($sequence) => ['slot' => $sequence->index + 1])
        ->create();

    $slots = app(CustomMinionSlots::class);

    expect($slots->isFull($user->fresh()))->toBe($full)
        ->and($slots->firstFree($user->fresh()))->toBe($full ? null : $held + 1);
})->with([
    'two of three' => [2, false],
    'three of three' => [3, true],
]);

it('follows an overridden minions_max', function () {
    config(['token_slayer.custom_character.minions_max' => 2]);
    $user = User::factory()->create();
    CustomMinion::factory()->for($user)->create(['slot' => 1]);
    CustomMinion::factory()->for($user)->create(['slot' => 2]);

    $slots = app(CustomMinionSlots::class);

    expect($slots->isFull($user->fresh()))->toBeTrue()
        ->and($slots->firstFree($user->fresh()))->toBeNull();
});
