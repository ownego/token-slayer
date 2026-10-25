<?php

use App\Enums\CustomMinionSource;
use App\Models\CustomMinion;
use App\Models\CustomMinionDraft;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('caps a player at three minions in config', function () {
    expect(config('token_slayer.custom_character.minions_max'))->toBe(3);
});

test('serialises a minion for the wire', function () {
    $minion = CustomMinion::factory()->create(['version_hash' => 'abc123def456', 'body_px' => 24]);

    expect($minion->broadcastState())->toBe([
        'version' => 'abc123def456',
        'url_base' => "/storage/custom-minions/{$minion->user_id}/abc123def456",
        'animations' => $minion->animations,
        'body_px' => 24,
    ]);
});

test('a minion built from the fighter remembers where it came from', function () {
    $minion = CustomMinion::factory()->create();
    $fromFighter = CustomMinion::factory()->fromFighter()->create();

    expect($minion->source)->toBe(CustomMinionSource::Upload)
        ->and($fromFighter->source)->toBe(CustomMinionSource::Fighter);
});

test('lists a player\'s minions by slot, whatever order they were added in', function () {
    $user = User::factory()->create();
    $second = CustomMinion::factory()->for($user)->create(['slot' => 2]);
    $first = CustomMinion::factory()->for($user)->create(['slot' => 1]);

    expect($user->customMinions->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and(User::factory()->create()->customMinions)->toBeEmpty();
});

test('a minion is named after its slot until it is renamed, and keeps its tick', function () {
    $minion = CustomMinion::factory()->create(['slot' => 3]);

    expect($minion->name)->toBe('Minion 3')
        ->and($minion->enabled)->toBeTrue();

    $minion->name = 'Pip';
    $minion->enabled = false;
    $minion->save();

    expect($minion->fresh()->name)->toBe('Pip')
        ->and($minion->fresh()->enabled)->toBeFalse()
        ->and($minion->fresh()->animations)->toBe($minion->animations);
});

test('refuses the same image twice for one player', function () {
    $user = User::factory()->create();
    CustomMinion::factory()->for($user)->create(['version_hash' => 'abc123def456', 'slot' => 1]);

    expect(fn () => CustomMinion::factory()->for($user)->create(['version_hash' => 'abc123def456', 'slot' => 2]))->toThrow(QueryException::class);
});

test('two players may hold the same image', function () {
    CustomMinion::factory()->create(['version_hash' => 'abc123def456']);

    expect(CustomMinion::factory()->create(['version_hash' => 'abc123def456']))->toBeInstanceOf(CustomMinion::class);
});

test('keeps one minion draft per player', function () {
    $user = User::factory()->create();
    $attributes = ['user_id' => $user->id, 'version_hash' => 'abc123def456', 'animations' => ['idle' => ['frames' => 6, 'rate' => 8]], 'body_px' => 22];
    $draft = CustomMinionDraft::create($attributes);

    expect($draft->animations)->toBe($attributes['animations'])
        ->and($draft->body_px)->toBe(22)
        ->and($draft->user->is($user))->toBeTrue()
        ->and($user->customMinionDraft->is($draft))->toBeTrue()
        ->and(fn () => CustomMinionDraft::create($attributes))->toThrow(QueryException::class);
});

test('deletes a player\'s minions and draft with the player', function () {
    $minion = CustomMinion::factory()->create();
    $user = $minion->user;
    CustomMinionDraft::create(['user_id' => $user->id, 'version_hash' => 'abc123def456', 'animations' => [], 'body_px' => 22]);

    $user->delete();

    expect(CustomMinion::count())->toBe(0)->and(CustomMinionDraft::count())->toBe(0);
});

test('deleting a player clears their minion files and leaves other players\' alone', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $other = User::factory()->create();
    Storage::disk('public')->put("custom-minions/{$user->id}/abc123def456/idle.png", 'x');
    Storage::disk('public')->put("custom-minions/{$other->id}/abc123def456/idle.png", 'x');

    $user->delete();

    Storage::disk('public')->assertMissing("custom-minions/{$user->id}/abc123def456/idle.png");
    Storage::disk('public')->assertExists("custom-minions/{$other->id}/abc123def456/idle.png");
});
