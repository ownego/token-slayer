<?php

use App\Models\Boss;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('event factory persists provider, event type, tokens, and raw payload', function () {
    $event = Event::factory()->for(User::factory())->for(Boss::factory())->create([
        'provider' => 'claude-code',
        'event_type' => 'stop',
        'tokens' => 23_400,
    ]);

    expect($event->raw_payload)->toBeArray()
        ->and($event->provider)->toBe('claude-code')
        ->and($event->event_type)->toBe('stop')
        ->and($event->tokens)->toBe(23_400);
});
