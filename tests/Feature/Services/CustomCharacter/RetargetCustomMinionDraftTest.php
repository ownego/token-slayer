<?php

use App\Exceptions\CustomCharacterBusyException;
use App\Models\CustomMinion;
use App\Models\CustomMinionDraft;
use App\Models\User;
use App\Services\CustomCharacter\RetargetCustomMinionDraft;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * A player with a minion in slot 1 and a draft built for them.
 *
 * @param  int|null  $slot  the slot the draft already replaces, null when it adds a minion
 * @return array{0: User, 1: CustomMinionDraft}
 */
function playerWithMinionAndDraft(?int $slot = null): array
{
    $user = User::factory()->create();
    CustomMinion::factory()->for($user)->create(['slot' => 1]);
    $draft = CustomMinionDraft::factory()->for($user)->create(['slot' => $slot, 'version_hash' => 'draft0000000']);

    return [$user, $draft];
}

test('retargeting a draft that already aims at the slot still refreshes its timestamp', function () {
    [$user, $draft] = playerWithMinionAndDraft(slot: 1);
    $old = now()->subDay()->startOfSecond();
    DB::table('custom_sprites')->where('id', $draft->id)->update(['updated_at' => $old]);
    $this->travelTo(now()->addMinutes(5));

    $retargeted = app(RetargetCustomMinionDraft::class)->execute($user, $draft->version_hash, 1);

    expect($retargeted->slot)->toBe(1)
        ->and($draft->fresh()->updated_at->greaterThan($old->copy()->addHour()))->toBeTrue();
});

test('retargeting a draft that aims elsewhere changes its slot and its timestamp', function () {
    [$user, $draft] = playerWithMinionAndDraft();
    $old = now()->subDay()->startOfSecond();
    DB::table('custom_sprites')->where('id', $draft->id)->update(['updated_at' => $old]);
    $this->travelTo(now()->addMinutes(5));

    app(RetargetCustomMinionDraft::class)->execute($user, $draft->version_hash, 1);

    expect($draft->fresh()->slot)->toBe(1)
        ->and($draft->fresh()->updated_at->greaterThan($old->copy()->addHour()))->toBeTrue();
});

test('retargeting waits for the player lock and leaves the draft as it was', function () {
    [$user, $draft] = playerWithMinionAndDraft();
    $before = $draft->fresh()->updated_at;
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    $held = Cache::lock(CacheKeys::customCharacterBuild($user->id), 30);
    $held->get();

    expect(fn () => app(RetargetCustomMinionDraft::class)->execute($user, $draft->version_hash, 1))
        ->toThrow(CustomCharacterBusyException::class);

    expect($draft->fresh()->slot)->toBeNull()
        ->and($draft->fresh()->updated_at->equalTo($before))->toBeTrue();

    $held->release();

    expect(app(RetargetCustomMinionDraft::class)->execute($user, $draft->version_hash, 1)->slot)->toBe(1);
});
