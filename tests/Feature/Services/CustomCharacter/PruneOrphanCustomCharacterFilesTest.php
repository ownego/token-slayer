<?php

use App\Models\CustomCharacter;
use App\Models\CustomCharacterDraft;
use App\Models\CustomMinion;
use App\Models\CustomMinionDraft;
use App\Models\User;
use App\Services\CustomCharacter\CustomCharacterLock;
use App\Services\CustomCharacter\CustomCharacterStore;
use App\Services\CustomCharacter\PruneOrphanCustomCharacterFiles;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

/**
 * Places an old idle strip where the orphan sweep looks for one.
 *
 * @param  string  $folder  folder relative to the public disk
 * @return void
 */
function oldStrip(string $folder): void
{
    $disk = Storage::disk('public');
    $disk->put("{$folder}/idle.png", 'strip');
    touch($disk->path("{$folder}/idle.png"), time() - 7200);
}

test('the sweep removes old orphan folders but keeps referenced versions and drafts', function () {
    $owner = User::factory()->create();
    $character = CustomCharacter::factory()->for($owner)->create(['version_hash' => 'live00000000']);
    CustomCharacterDraft::create(['user_id' => $owner->id, 'version_hash' => 'draft0000000', 'animations' => $character->animations]);
    $base = "custom-characters/{$owner->id}";
    oldStrip("{$base}/live00000000");
    oldStrip("{$base}/orphan000000");
    oldStrip("{$base}/staged/draft0000000");

    expect(app(PruneOrphanCustomCharacterFiles::class)->execute())->toBe(1);
    Storage::disk('public')->assertExists("{$base}/live00000000/idle.png");
    Storage::disk('public')->assertExists("{$base}/staged/draft0000000/idle.png");
    Storage::disk('public')->assertMissing("{$base}/orphan000000/idle.png");
});

test('the sweep removes an old unreferenced staged area and keeps recent or unknown-age folders', function () {
    $owner = User::factory()->create();
    $base = "custom-characters/{$owner->id}";
    oldStrip("{$base}/staged/unused000000");
    Storage::disk('public')->put("{$base}/recent000000/idle.png", 'new');
    Storage::disk('public')->put("{$base}/unknown00000/walk.png", 'no idle');
    oldStrip('custom-characters/not-a-user/old000000000');

    expect(app(PruneOrphanCustomCharacterFiles::class)->execute())->toBe(1);
    Storage::disk('public')->assertMissing("{$base}/staged/unused000000/idle.png");
    Storage::disk('public')->assertExists("{$base}/recent000000/idle.png");
    Storage::disk('public')->assertExists("{$base}/unknown00000/walk.png");
    Storage::disk('public')->assertExists('custom-characters/not-a-user/old000000000/idle.png');
});

test('the sweep skips folders when bucket metadata cannot provide an age', function () {
    $owner = User::factory()->create();
    $folder = "custom-characters/{$owner->id}/orphan000000";
    oldStrip($folder);
    $real = Storage::disk('public');
    $bucket = Mockery::mock($real);
    $bucket->shouldReceive('lastModified')->andThrow(new RuntimeException('No metadata'));
    Storage::set('public', $bucket);

    expect(app(PruneOrphanCustomCharacterFiles::class)->execute())->toBe(0);
    $real->assertExists("{$folder}/idle.png");
});

test('the sweep rechecks version references after acquiring the player lock', function () {
    $owner = User::factory()->create();
    $folder = "custom-characters/{$owner->id}/justsaved000";
    oldStrip($folder);
    $lock = Mockery::mock(CustomCharacterLock::class);
    $lock->shouldReceive('within')->once()->andReturnUsing(function (int $userId, Closure $callback) use ($owner): int {
        expect($userId)->toBe($owner->id);
        CustomCharacter::factory()->for($owner)->create(['version_hash' => 'justsaved000']);

        return $callback();
    });

    expect((new PruneOrphanCustomCharacterFiles(app(CustomCharacterStore::class), $lock))->execute())->toBe(0);
    Storage::disk('public')->assertExists("{$folder}/idle.png");
});

test('it sweeps an unreferenced minion folder older than an hour and keeps referenced ones', function () {
    $owner = User::factory()->create();
    CustomMinion::factory()->for($owner)->create(['version_hash' => 'minlive00000']);
    CustomMinionDraft::create(['user_id' => $owner->id, 'version_hash' => 'mindraft0000', 'animations' => CustomMinion::first()->animations, 'body_px' => 22]);
    $base = "custom-minions/{$owner->id}";
    oldStrip("{$base}/minlive00000");
    oldStrip("{$base}/minorphan000");
    oldStrip("{$base}/staged/mindraft0000");
    Storage::disk('public')->put("{$base}/minrecent000/idle.png", 'new');
    $strayStaged = User::factory()->create();
    oldStrip("custom-minions/{$strayStaged->id}/staged/minunused000");

    expect(app(PruneOrphanCustomCharacterFiles::class)->execute())->toBe(2);
    Storage::disk('public')->assertExists("{$base}/minlive00000/idle.png");
    Storage::disk('public')->assertExists("{$base}/staged/mindraft0000/idle.png");
    Storage::disk('public')->assertExists("{$base}/minrecent000/idle.png");
    Storage::disk('public')->assertMissing("{$base}/minorphan000/idle.png");
    Storage::disk('public')->assertMissing("custom-minions/{$strayStaged->id}/staged/minunused000/idle.png");
});

test('it never sweeps minion folders as fighter orphans, nor fighter folders as minion orphans', function () {
    $minionOnly = User::factory()->create();
    CustomMinion::factory()->for($minionOnly)->create(['version_hash' => 'minionone000']);
    CustomMinion::factory()->for($minionOnly)->create(['version_hash' => 'miniontwo000']);
    oldStrip("custom-minions/{$minionOnly->id}/minionone000");
    oldStrip("custom-minions/{$minionOnly->id}/miniontwo000");
    $fighterOnly = User::factory()->create();
    CustomCharacter::factory()->for($fighterOnly)->create(['version_hash' => 'fighterone00']);
    oldStrip("custom-characters/{$fighterOnly->id}/fighterone00");

    expect(app(PruneOrphanCustomCharacterFiles::class)->execute())->toBe(0);
    Storage::disk('public')->assertExists("custom-minions/{$minionOnly->id}/minionone000/idle.png");
    Storage::disk('public')->assertExists("custom-minions/{$minionOnly->id}/miniontwo000/idle.png");
    Storage::disk('public')->assertExists("custom-characters/{$fighterOnly->id}/fighterone00/idle.png");
});

test('the orphan sweep command is scheduled daily without overlap', function () {
    $scheduled = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'custom-characters:prune-orphans'));

    expect($scheduled)->not->toBeNull()
        ->and($scheduled->expression)->toBe('30 4 * * *')
        ->and($scheduled->onOneServer)->toBeTrue();
});
