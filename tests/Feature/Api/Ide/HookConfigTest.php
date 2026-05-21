<?php

use App\Models\IdeAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('returns namespace and per-event URLs', function () {
    config()->set('app.hook_namespace', 'aiorg');

    $user = User::factory()->create();
    [$plain] = IdeAccessToken::issueBearer($user);

    $response = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->getJson('/api/ide/hook-config')
        ->assertOk()
        ->assertJsonStructure([
            'namespace',
            'eventsUrl',
            'events' => [
                '*' => ['name', 'command'],
            ],
        ]);

    expect($response->json('namespace'))->toBe('aiorg');
    expect($response->json('eventsUrl'))->toBe(url('/api/events'));

    $eventNames = collect($response->json('events'))->pluck('name')->all();
    expect($eventNames)->toContain('Stop', 'SessionStart');
});

test('401 without bearer', function () {
    $this->getJson('/api/ide/hook-config')->assertUnauthorized();
});
