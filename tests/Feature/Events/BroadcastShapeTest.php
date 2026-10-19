<?php

use App\Events\BossKilled;
use App\Events\BossSpawned;
use App\Events\FighterAgentCountChanged;
use App\Events\FighterAgentToolUsed;
use App\Events\FighterCharacterChanged;
use App\Events\FighterChargeCleared;
use App\Events\FighterCharging;
use App\Events\FighterIdled;
use App\Events\FighterJoined;
use App\Events\FighterMinionsChanged;
use App\Events\FighterMoved;
use App\Events\HitDealt;
use App\Models\Boss;
use App\Models\CustomCharacter;
use App\Models\CustomMinion;
use App\Models\User;
use App\Services\FighterPositionCache;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('FighterAgentToolUsed broadcasts on the battlefield channel with expected payload', function () {
    $user = User::factory()->create();
    $event = new FighterAgentToolUsed($user, 'agent-abc123', true);

    expect($event)->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastOn()[0]->name)->toBe('battlefield')
        ->and($event->broadcastAs())->toBe('FighterAgentToolUsed')
        ->and($event->broadcastWith())->toMatchArray([
            'user_id' => $user->id,
            'agent_id' => 'agent-abc123',
            'busy' => true,
        ]);
});

test('HitDealt broadcasts on the battlefield channel with expected payload', function () {
    $user = User::factory()->create();
    $boss = Boss::factory()->create(['number' => 1]);
    $event = new HitDealt($user, 1234, $boss);

    expect($event->broadcastOn()[0]->name)->toBe('battlefield')
        ->and($event->broadcastAs())->toBe('HitDealt')
        ->and($event->broadcastWith())->toMatchArray([
            'user_id' => $user->id,
            'damage' => 1234,
            'boss_id' => $boss->id,
        ]);
});

test('every battlefield event broadcasts now on the battlefield channel with a short broadcastAs name', function () {
    $user = User::factory()->create();
    $boss = Boss::factory()->create();

    $events = [
        'HitDealt' => new HitDealt($user, 1, $boss),
        'BossKilled' => new BossKilled($boss, $user),
        'BossSpawned' => new BossSpawned($boss),
        'FighterCharging' => new FighterCharging($user),
        'FighterJoined' => new FighterJoined($user),
        'FighterIdled' => new FighterIdled($user),
        'FighterChargeCleared' => new FighterChargeCleared($user),
        'FighterCharacterChanged' => new FighterCharacterChanged($user),
        'FighterMinionsChanged' => new FighterMinionsChanged($user),
        'FighterMoved' => new FighterMoved($user, 0.5, 0.7),
        'FighterAgentCountChanged' => new FighterAgentCountChanged($user, 1, 1),
        'FighterAgentToolUsed' => new FighterAgentToolUsed($user, 'agent-abc123', true),
    ];

    foreach ($events as $shortName => $event) {
        expect($event)->toBeInstanceOf(ShouldBroadcastNow::class)
            ->and($event->broadcastOn()[0]->name)->toBe('battlefield')
            ->and($event->broadcastAs())->toBe($shortName);
    }
});

test('FighterJoined broadcasts the character assigned for the given boss', function () {
    $user = User::factory()->create();
    $boss = Boss::factory()->create();

    $withBoss = new FighterJoined($user, $boss);
    $withoutBoss = new FighterJoined($user);

    expect($withBoss->broadcastWith())->toMatchArray([
        'user_id' => $user->id,
        'character' => $user->characterForBoss($boss->id),
    ])->and($withoutBoss->broadcastWith()['character'])->toBe($user->characterForBoss(null));
});

test('FighterJoined payload includes display_name and avatar_url', function () {
    $user = User::factory()->create(['display_name' => 'Alice']);
    $boss = Boss::factory()->create();

    $payload = (new FighterJoined($user, $boss))->broadcastWith();

    expect($payload)->toHaveKey('display_name', 'Alice')
        ->and($payload)->toHaveKey('avatar_url')
        ->and($payload['avatar_url'])->toStartWith('http');
});

test('FighterJoined carries the rejoining fighter saved position', function () {
    $user = User::factory()->create();
    $boss = Boss::factory()->create();
    app(FighterPositionCache::class)->put($user->id, 0.42, 0.66);

    $payload = (new FighterJoined($user, $boss))->broadcastWith();

    expect($payload['position'])->toBe(['x' => 0.42, 'y' => 0.66]);
});

test('FighterJoined carries a null position for a fighter that never moved', function () {
    $user = User::factory()->create();
    $boss = Boss::factory()->create();

    $payload = (new FighterJoined($user, $boss))->broadcastWith();

    expect($payload)->toHaveKey('position')
        ->and($payload['position'])->toBeNull();
});

test('FighterCharging broadcasts character for the given boss', function () {
    $user = User::factory()->create();
    $boss = Boss::factory()->create();

    $withBoss = new FighterCharging($user, 'thinking…', $boss);
    $withoutBoss = new FighterCharging($user, '$ npm install');

    expect($withBoss->broadcastWith())->toMatchArray([
        'user_id' => $user->id,
        'character' => $user->characterForBoss($boss->id),
        'activity' => 'thinking…',
    ])->and($withoutBoss->broadcastWith()['character'])->toBe($user->characterForBoss(null));
});

test('FighterCharging carries the fighter saved position, so a synthetic client-side rejoin restores it', function () {
    $user = User::factory()->create();
    app(FighterPositionCache::class)->put($user->id, 0.42, 0.66);

    $payload = (new FighterCharging($user, 'thinking…'))->broadcastWith();

    expect($payload['position'])->toBe(['x' => 0.42, 'y' => 0.66]);
});

test('FighterCharging carries a null position for a fighter that never moved', function () {
    $user = User::factory()->create();

    $payload = (new FighterCharging($user, 'thinking…'))->broadcastWith();

    expect($payload)->toHaveKey('position')
        ->and($payload['position'])->toBeNull();
});

test('FighterChargeCleared broadcasts on the battlefield channel with expected payload', function () {
    $user = User::factory()->create();
    $event = new FighterChargeCleared($user);

    expect($event->broadcastOn()[0]->name)->toBe('battlefield')
        ->and($event->broadcastAs())->toBe('FighterChargeCleared')
        ->and($event->broadcastWith())->toBe([
            'user_id' => $user->id,
        ]);
});

test('FighterMoved broadcasts on the battlefield channel with expected payload', function () {
    $user = User::factory()->create();
    $event = new FighterMoved($user, 0.35, 0.72);

    expect($event)->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastOn()[0]->name)->toBe('battlefield')
        ->and($event->broadcastAs())->toBe('FighterMoved')
        ->and($event->broadcastWith())->toBe([
            'user_id' => $user->id,
            'x' => 0.35,
            'y' => 0.72,
        ]);
});

test('FighterCharacterChanged broadcasts on the battlefield channel with the user\'s current character', function () {
    $user = User::factory()->create(['equipped_character' => 'archer']);
    $event = new FighterCharacterChanged($user);

    expect($event->broadcastOn()[0]->name)->toBe('battlefield')
        ->and($event->broadcastAs())->toBe('FighterCharacterChanged')
        ->and($event->broadcastWith())->toBe([
            'user_id' => $user->id,
            'character' => 'archer',
            'custom_character' => null,
        ]);
});

test('HitDealt carries a nullable model, flair, flair duration, and flair color', function () {
    // All four must be nullable end to end: HitDealt is also dispatched for
    // cowork/claude-ai Stops, where no model exists at all.
    $user = User::factory()->create();
    $boss = Boss::factory()->create(['number' => 1]);

    $plain = (new HitDealt($user, 100, $boss))->broadcastWith();

    expect($plain)->toHaveKeys(['model', 'flair', 'flair_duration_ms', 'flair_color'])
        ->and($plain['model'])->toBeNull()
        ->and($plain['flair'])->toBeNull()
        ->and($plain['flair_duration_ms'])->toBeNull()
        ->and($plain['flair_color'])->toBeNull();

    $fable = (new HitDealt($user, 100, $boss, 'claude-fable-5-1', 'fable', 9000, '#a855f7'))->broadcastWith();

    expect($fable['model'])->toBe('claude-fable-5-1')
        ->and($fable['flair'])->toBe('fable')
        ->and($fable['flair_duration_ms'])->toBe(9000)
        ->and($fable['flair_color'])->toBe('#a855f7');
});

test('HitDealt carries the hit\'s input and cache tokens as ints, zero when unknown', function () {
    $user = User::factory()->create();
    $boss = Boss::factory()->create(['number' => 1]);

    expect((new HitDealt($user, 100, $boss))->broadcastWith())
        ->toMatchArray(['input_tokens' => 0, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0]);

    $full = (new HitDealt($user, 100, $boss, inputTokens: 12, cacheCreationInputTokens: 340, cacheReadInputTokens: 5600))->broadcastWith();

    expect($full)->toMatchArray(['input_tokens' => 12, 'cache_creation_input_tokens' => 340, 'cache_read_input_tokens' => 5600]);
});

test('HitDealt sends only scalars, per the payload rule', function () {
    $user = User::factory()->create();
    $boss = Boss::factory()->create(['number' => 1]);

    foreach ((new HitDealt($user, 100, $boss, 'claude-fable-5-1', 'fable', 9000, '#a855f7'))->broadcastWith() as $key => $value) {
        expect(is_scalar($value) || $value === null)->toBeTrue("payload key {$key} is not a scalar");
    }
});

test('BossSpawned carries the stone clock for a boss whose script has one', function () {
    $this->travelTo('2026-09-23T08:00:00Z'); // 15:00 local, after the 14:00 tick
    $boss = Boss::factory()->thanode()->create(['spawned_at' => '2026-09-23T03:00:00Z']);
    $payload = (new BossSpawned($boss))->broadcastWith();

    expect($payload)->toMatchArray([
        'stones' => 2,
        'stone_schedule' => '2026-09-23T10:50:00Z,2026-09-24T02:30:00Z,2026-09-24T07:00:00Z,2026-09-24T10:50:00Z',
    ]);
    expect($payload['stone_schedule'])->toBeString(); // scalar on the wire, split by the client
});

test('BossSpawned leaves script keys out for a generic monster, even in the old thanos slot', function () {
    $boss = Boss::factory()->create(['number' => 55, 'name' => 'Smaug']);

    expect((new BossSpawned($boss))->broadcastWith())
        ->not->toHaveKey('stones')
        ->not->toHaveKey('stone_schedule');
});

test('FighterAgentCountChanged broadcasts on the battlefield channel with expected payload', function () {
    $user = User::factory()->create();
    $event = new FighterAgentCountChanged($user, 2, 7);

    expect($event->broadcastOn()[0]->name)->toBe('battlefield')
        ->and($event->broadcastAs())->toBe('FighterAgentCountChanged')
        ->and($event->broadcastWith())->toBe([
            'user_id' => $user->id,
            'count' => 2,
            'seq' => 7,
        ]);
});

test('every broadcast avatar is the versioned proxy URL, never the raw 512px Slack image', function () {
    // The raw URL makes the browser download Slack's ~600 KB original for a
    // 30px head; the proxy serves the 192px variant, cached for a week.
    $user = User::factory()->create(['avatar_url' => 'https://avatars.slack-edge.com/a_512.png']);
    $boss = Boss::factory()->create();

    expect((new HitDealt($user, 1, $boss))->broadcastWith()['avatar_url'])->toBe($user->avatarProxyUrl())
        ->and((new FighterCharging($user))->broadcastWith()['avatar_url'])->toBe($user->avatarProxyUrl())
        ->and((new FighterJoined($user, $boss))->broadcastWith()['avatar_url'])->toBe($user->avatarProxyUrl())
        ->and((new BossKilled($boss, $user))->broadcastWith()['killer_avatar_url'])->toBe($user->avatarProxyUrl());
});

test('FighterJoined carries the custom character a user built, and null for one without', function () {
    $boss = Boss::factory()->create(['number' => 1]);
    $plain = User::factory()->create();
    $custom = CustomCharacter::factory()->create()->user;

    expect((new FighterJoined($plain, $boss))->broadcastWith())->toHaveKey('custom_character', null)
        ->and((new FighterJoined($custom, $boss))->broadcastWith()['custom_character'])->toBe($custom->customFighterState());
});

test('FighterCharacterChanged carries the custom character so every client can load or drop it', function () {
    $plain = User::factory()->create();
    $custom = CustomCharacter::factory()->create()->user;

    expect((new FighterCharacterChanged($plain))->broadcastWith())->toHaveKey('custom_character', null)
        ->and((new FighterCharacterChanged($custom))->broadcastWith()['custom_character'])->toBe($custom->customFighterState());
});

test('BossSpawned lists each fighter with its custom character, so a boss change never re-skins it', function () {
    $boss = Boss::factory()->create(['number' => 2]);
    $plain = User::factory()->create(['last_event_at' => now()]);
    $custom = CustomCharacter::factory()->create()->user;
    $custom->forceFill(['last_event_at' => now()])->save();

    $fighters = collect((new BossSpawned($boss))->broadcastWith()['fighters'])->keyBy('user_id');

    expect($fighters[$plain->id])->toHaveKey('custom_character', null)
        ->and($fighters[$custom->id]['custom_character'])->toBe($custom->customFighterState());
});

test('FighterCharging carries the custom character, so a synthetic client-side rejoin shows it', function () {
    $plain = User::factory()->create();
    $custom = CustomCharacter::factory()->create()->user;

    expect((new FighterCharging($plain, 'thinking…'))->broadcastWith())->toHaveKey('custom_character', null)
        ->and((new FighterCharging($custom, 'thinking…'))->broadcastWith()['custom_character'])->toBe($custom->customFighterState());
});

test('the custom character payload has exactly these keys, so a rename on either side is caught', function () {
    $character = CustomCharacter::factory()->create(['version_hash' => 'abc123def456']);
    $folder = '/storage/custom-characters/'.$character->user_id.'/abc123def456';

    foreach ([
        new FighterJoined($character->user),
        new FighterCharacterChanged($character->user),
        new FighterCharging($character->user, 'thinking'),
    ] as $event) {
        expect($event->broadcastWith()['custom_character'])->toBe([
            'version' => 'abc123def456',
            'url_base' => $folder,
            'animations' => [
                'idle' => ['frames' => 6, 'rate' => 8],
                'walk' => ['frames' => 8, 'rate' => 10],
                'attack' => ['frames' => 6, 'rate' => 12],
                'death' => ['frames' => 3, 'rate' => 6],
            ],
        ]);
    }
});

test('FighterMinionsChanged carries the player\'s minion set', function () {
    $minion = CustomMinion::factory()->create(['version_hash' => 'abc123def456']);
    $user = $minion->user;
    $plain = User::factory()->create();

    expect((new FighterMinionsChanged($user))->broadcastWith())->toBe([
        'user_id' => $user->id,
        'custom_minions' => [$minion->broadcastState()],
    ])->and((new FighterMinionsChanged($plain))->broadcastWith())->toBe([
        'user_id' => $plain->id,
        'custom_minions' => [],
    ]);
});

test('FighterJoined and FighterCharging carry custom_minions', function () {
    $boss = Boss::factory()->create(['number' => 1]);
    $plain = User::factory()->create();
    $minions = CustomMinion::factory()->count(2)->create();
    $owner = $minions->first()->user;
    CustomMinion::factory()->create(['user_id' => $owner->id]);
    $owner = $owner->fresh();

    expect((new FighterJoined($plain, $boss))->broadcastWith())->toHaveKey('custom_minions', [])
        ->and((new FighterCharging($plain, 'thinking…'))->broadcastWith())->toHaveKey('custom_minions', [])
        ->and((new FighterJoined($owner, $boss))->broadcastWith()['custom_minions'])->toBe($owner->customMinionsState())
        ->and((new FighterCharging($owner, 'thinking…'))->broadcastWith()['custom_minions'])->toBe($owner->customMinionsState())
        ->and($owner->customMinionsState())->toHaveCount(2);
});

test('BossSpawned fighters do not carry custom_minions', function () {
    // BossSpawned repeats every active fighter, so a minion set per row would push it past Reverb's limit.
    $boss = Boss::factory()->create(['number' => 2]);
    $minion = CustomMinion::factory()->create();
    $minion->user->forceFill(['last_event_at' => now()])->save();
    User::factory()->create(['last_event_at' => now()]);

    $fighters = (new BossSpawned($boss))->broadcastWith()['fighters'];

    expect($fighters)->toHaveCount(2);
    foreach ($fighters as $row) {
        expect(array_keys($row))->toBe(['user_id', 'character', 'custom_character']);
    }
});

test('fighter payloads with a custom fighter and three minions stay under Reverb\'s request limit', function () {
    $character = CustomCharacter::factory()->create(['version_hash' => 'abc123def456']);
    $user = $character->user;
    CustomMinion::factory()->count(3)->create(['user_id' => $user->id]);
    $user = $user->fresh();
    $limit = config('reverb.servers.reverb.max_request_size', 10_000);

    foreach ([
        new FighterJoined($user),
        new FighterCharging($user, 'thinking…'),
        new FighterMinionsChanged($user),
    ] as $event) {
        expect(strlen(json_encode($event->broadcastWith())))->toBeLessThan($limit);
    }
});
