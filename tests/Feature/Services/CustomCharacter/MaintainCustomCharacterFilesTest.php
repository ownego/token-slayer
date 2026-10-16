<?php

use App\Models\CustomCharacter;
use App\Models\CustomCharacterDraft;
use App\Models\User;
use App\Services\CustomCharacter\CopyCustomCharacterDisk;
use App\Services\CustomCharacter\PruneStaleCustomCharacterDrafts;
use App\Support\CacheKeys;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

test('a draft nobody used within the time limit goes, with its staged files; a fresh one and the fighter in use stay', function () {
    config(['token_slayer.custom_character.draft_ttl_hours' => 24]);
    [$stale, $fresh, $owner] = User::factory()->count(3)->create();
    $old = stagedFor($stale);
    $new = stagedFor($fresh);
    $character = CustomCharacter::factory()->create(['user_id' => $owner->id]);
    $ownersDraft = stagedFor($owner);
    Storage::disk('public')->put("custom-characters/{$owner->id}/{$character->version_hash}/idle.png", 'x');
    CustomCharacterDraft::whereKey($old->id)->update(['created_at' => now()->subHours(25), 'updated_at' => now()->subHours(25)]);

    $removed = app(PruneStaleCustomCharacterDrafts::class)->execute();

    expect($removed)->toBe(1)
        ->and(CustomCharacterDraft::whereKey($old->id)->exists())->toBeFalse()
        ->and(CustomCharacterDraft::whereKey($new->id)->exists())->toBeTrue()
        ->and(CustomCharacterDraft::whereKey($ownersDraft->id)->exists())->toBeTrue()
        ->and(CustomCharacter::count())->toBe(1);
    Storage::disk('public')->assertMissing("custom-characters/{$stale->id}/staged/{$old->version_hash}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/{$fresh->id}/staged/{$new->version_hash}/idle.png");
    Storage::disk('public')->assertExists("custom-characters/{$owner->id}/{$character->version_hash}/idle.png");
});

test('a stale draft of a player with a change running is left for the next pass', function () {
    config(['token_slayer.custom_character.draft_ttl_hours' => 24, 'token_slayer.custom_character.lock_seconds' => 0]);
    $user = User::factory()->create();
    $draft = stagedFor($user);
    CustomCharacterDraft::whereKey($draft->id)->update(['updated_at' => now()->subHours(48)]);
    Cache::lock(CacheKeys::customCharacterBuild($user->id), 30)->get();

    expect(app(PruneStaleCustomCharacterDrafts::class)->execute())->toBe(0)
        ->and(CustomCharacterDraft::whereKey($draft->id)->exists())->toBeTrue();
});

test('the prune command runs, reports, and is scheduled', function () {
    $this->artisan('custom-characters:prune-drafts')->expectsOutputToContain('0 stale drafts removed')->assertSuccessful();

    $scheduled = collect(app(Schedule::class)->events())->contains(fn ($event) => str_contains($event->command, 'custom-characters:prune-drafts'));
    expect($scheduled)->toBeTrue();
});

test('copying to another disk brings every file of every player across, so a changed disk setting orphans nothing', function () {
    Storage::fake('bucket');
    Storage::disk('public')->put('custom-characters/7/abc/idle.png', 'one');
    Storage::disk('public')->put('custom-characters/7/staged/def/walk.png', 'two');
    Storage::disk('public')->put('custom-characters/9/xyz/death.png', 'three');
    Storage::disk('public')->put('avatars/keep.png', 'not ours');

    $copied = app(CopyCustomCharacterDisk::class)->execute('public', 'bucket');

    expect($copied)->toBe(3);
    Storage::disk('bucket')->assertExists('custom-characters/7/abc/idle.png');
    Storage::disk('bucket')->assertExists('custom-characters/7/staged/def/walk.png');
    Storage::disk('bucket')->assertExists('custom-characters/9/xyz/death.png');
    Storage::disk('bucket')->assertMissing('avatars/keep.png');
    expect(Storage::disk('bucket')->get('custom-characters/9/xyz/death.png'))->toBe('three');
    Storage::disk('public')->assertExists('custom-characters/7/abc/idle.png');
});

test('the copy command names both disks and says how many files it copied', function () {
    Storage::fake('bucket');
    Storage::disk('public')->put('custom-characters/7/abc/idle.png', 'one');

    $this->artisan('custom-characters:copy-disk', ['from' => 'public', 'to' => 'bucket'])
        ->expectsOutputToContain('Copied 1 file from public to bucket')
        ->assertSuccessful();
});
