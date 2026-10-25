<?php

use App\Events\FighterCharging;
use App\Events\FighterJoined;
use App\Events\FighterMinionsChanged;
use App\Models\Boss;
use App\Models\CustomCharacter;
use App\Models\CustomMinion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The body the Pusher client would POST to Reverb for an event: the same three
 * keys it builds in `Pusher::trigger()`, with `data` encoded to a string first.
 *
 * @param  object  $event  a broadcast event
 * @return string the request body
 */
function pusherBodyFor(object $event): string
{
    return json_encode([
        'name' => $event->broadcastAs(),
        'channels' => ['battlefield'],
        'data' => json_encode($event->broadcastWith()),
    ]);
}

it('keeps the largest minion pool payload under 10000 bytes', function () {
    // 10 000 bytes is Reverb's default `max_request_size`: a larger trigger request is
    // refused, and the hook path swallows the failure. The pool's `url_base` is a full
    // URL when the strips live on a bucket, which is what makes it long. Worst case: a
    // long bucket host and prefix, a full set of three minions, a custom fighter on the
    // same disk, a long name.
    config([
        'filesystems.disks.bucket' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/bucket'),
            'url' => 'https://pub-0123456789abcdef0123456789abcdef.r2.example-cdn.com/token-slayer/production/user-uploads/media',
            'visibility' => 'public',
        ],
        'token_slayer.custom_character.disk' => 'bucket',
    ]);

    $boss = Boss::factory()->create();
    $user = CustomCharacter::factory()->create()->user;
    CustomMinion::factory()->count(3)
        ->sequence(fn ($sequence) => ['slot' => $sequence->index + 1])
        ->create(['user_id' => $user->id]);
    $user->forceFill(['display_name' => str_repeat('x', 100), 'minion_pool' => ['rev' => 999999, 'defaults_off' => [], 'slots_off' => []]])->save();
    $user = $user->fresh();

    expect($user->minionPoolState()['custom'])->toHaveCount(3)
        ->and($user->minionPoolState()['custom'][0]['url_base'])->toStartWith('https://');

    foreach ([
        new FighterJoined($user, $boss),
        new FighterCharging($user, str_repeat('a', 40), $boss),
        new FighterMinionsChanged($user),
    ] as $event) {
        expect(strlen(pusherBodyFor($event)))->toBeLessThan(10_000);
    }
});
