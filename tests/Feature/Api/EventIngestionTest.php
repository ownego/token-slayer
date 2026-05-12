<?php

use App\Events\BossKilled;
use App\Events\BossSpawned;
use App\Events\HitDealt;
use App\Models\Boss;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['hook_token' => hash('sha256', 'tok')]);
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000_000, 'current_hp' => 1_000_000]);
});

test('rejects unauthenticated requests', function () {
    $this->postJson('/api/events', ['hook_event_name' => 'SessionStart'])->assertStatus(401);
});

test('records a non-Stop event and updates last_event_at', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'SessionStart',
            'session_id' => 'sess-abc',
            'cwd' => '/home/dev/project',
        ])
        ->assertCreated();

    $event = Event::sole();
    expect($event->event_type)->toBe('session-start')
        ->and($event->provider)->toBe('claude-code')
        ->and($event->user_id)->toBe($this->user->id)
        ->and($event->session_id)->toBe('sess-abc')
        ->and($event->raw_payload['cwd'])->toBe('/home/dev/project');

    expect($this->user->fresh()->last_event_at)->not->toBeNull();
});

test('Stop event with tokens damages the current boss and broadcasts HitDealt', function () {
    Illuminate\Support\Facades\Event::fake([HitDealt::class]);

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop',
            'session_id' => 'sess-1',
            'tokens' => 250_000,
        ])
        ->assertCreated();

    $boss = Boss::sole();
    expect($boss->current_hp)->toBe(750_000);

    Illuminate\Support\Facades\Event::assertDispatched(HitDealt::class, function ($e) {
        return $e->damage === 250_000 && $e->boss->current_hp === 750_000;
    });
});

test('Stop event without inline tokens reads damage from the transcript file', function () {
    $transcript = tempnam(sys_get_temp_dir(), 'transcript-');
    file_put_contents($transcript, collect([
        ['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'go']]]],
        ['type' => 'assistant', 'message' => ['usage' => ['output_tokens' => 120_000]]],
        ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'content' => 'ok']]]],
        ['type' => 'assistant', 'message' => ['usage' => ['output_tokens' => 80_000]]],
    ])->map(fn ($e) => json_encode($e))->implode("\n"));

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop',
            'session_id' => 'sess-transcript',
            'transcript_path' => $transcript,
        ])
        ->assertCreated();

    expect(Boss::sole()->current_hp)->toBe(800_000)
        ->and(Event::where('event_type', 'stop')->sole()->tokens)->toBe(200_000);

    @unlink($transcript);
});

test('Stop event still applies damage when a broadcast listener throws', function () {
    Illuminate\Support\Facades\Event::listen(HitDealt::class, function () {
        throw new RuntimeException('simulated broadcast failure');
    });

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop',
            'session_id' => 'sess-broadcast-down',
            'tokens' => 100_000,
        ])
        ->assertCreated();

    expect(Boss::sole()->current_hp)->toBe(900_000);
});

test('Stop event killing the boss broadcasts BossKilled then BossSpawned', function () {
    Boss::query()->delete();
    Boss::factory()->create(['number' => 1, 'max_hp' => 100, 'current_hp' => 100]);
    Illuminate\Support\Facades\Event::fake([
        HitDealt::class,
        BossKilled::class,
        BossSpawned::class,
    ]);

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', ['hook_event_name' => 'Stop', 'tokens' => 350])
        ->assertCreated();

    Illuminate\Support\Facades\Event::assertDispatched(BossKilled::class);
    Illuminate\Support\Facades\Event::assertDispatched(BossSpawned::class);
});
