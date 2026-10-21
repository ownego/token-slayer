<?php

use App\Enums\CustomMinionSource;
use App\Models\CustomMinion;
use App\Models\CustomMinionDraft;
use App\Models\User;
use App\Services\CustomCharacter\CustomMinionStore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

test('the sheet state adds the id and where the minion came from', function () {
    $minion = CustomMinion::factory()->create();
    $fromFighter = CustomMinion::factory()->fromFighter()->create();

    expect($minion->sheetState())->toBe([
        'id' => $minion->id,
        'version' => $minion->version_hash,
        'url_base' => app(CustomMinionStore::class)->baseUrl($minion->user_id, $minion->version_hash),
        'animations' => $minion->animations,
        'body_px' => 22,
        'source' => 'upload',
    ])->and($fromFighter->sheetState()['source'])->toBe('fighter')
        ->and($fromFighter->source)->toBe(CustomMinionSource::Fighter);
});

test('lists a player\'s minions in id order', function () {
    $user = User::factory()->create();
    $first = CustomMinion::factory()->for($user)->create();
    $second = CustomMinion::factory()->for($user)->create();

    expect($user->customMinionsState())->toBe([$first->broadcastState(), $second->broadcastState()])
        ->and($user->customMinions->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and(User::factory()->create()->customMinionsState())->toBe([]);
});

test('the state reads through the relation, so an eager load is used', function () {
    $user = User::factory()->create();
    CustomMinion::factory()->for($user)->create();
    $loaded = User::with('customMinions')->find($user->id);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    $loaded->customMinionsState();

    expect($queries)->toBe(0);
});

test('refuses the same image twice for one player', function () {
    $user = User::factory()->create();
    CustomMinion::factory()->for($user)->create(['version_hash' => 'abc123def456']);

    expect(fn () => CustomMinion::factory()->for($user)->create(['version_hash' => 'abc123def456']))->toThrow(QueryException::class);
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
