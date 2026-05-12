<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

uses(RefreshDatabase::class);

test('slack callback creates user, generates hook token, logs in', function () {
    $slackUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $slackUser->shouldReceive('getId')->andReturn('U123');
    $slackUser->shouldReceive('getName')->andReturn('Alice Liu');
    $slackUser->shouldReceive('getNickname')->andReturn('alice');
    $slackUser->shouldReceive('getEmail')->andReturn('alice@example.com');
    $slackUser->shouldReceive('getAvatar')->andReturn('https://avatar/alice.png');

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->andReturn($slackUser);
    Socialite::shouldReceive('driver')->with('slack')->andReturn($provider);

    $this->get('/auth/slack/callback')->assertRedirect('/profile');

    $user = User::sole();
    expect($user->slack_user_id)->toBe('U123')
        ->and($user->slack_handle)->toBe('alice')
        ->and($user->avatar_url)->toBe('https://avatar/alice.png')
        ->and($user->hook_token)->not->toBeNull()
        ->and(auth()->id())->toBe($user->id);

    expect(session('hook_token_plain'))->not->toBeNull();
});

test('slack callback for returning user keeps hook_token and redirects to battlefield', function () {
    $existing = User::factory()->create([
        'slack_user_id' => 'U999',
        'slack_handle' => 'old-handle',
        'display_name' => 'Old Name',
        'avatar_url' => 'https://avatar/old.png',
    ]);
    $originalToken = $existing->hook_token;

    $slackUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $slackUser->shouldReceive('getId')->andReturn('U999');
    $slackUser->shouldReceive('getName')->andReturn('New Name');
    $slackUser->shouldReceive('getNickname')->andReturn('new-handle');
    $slackUser->shouldReceive('getEmail')->andReturn('new@example.com');
    $slackUser->shouldReceive('getAvatar')->andReturn('https://avatar/new.png');

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->andReturn($slackUser);
    Socialite::shouldReceive('driver')->with('slack')->andReturn($provider);

    $this->get('/auth/slack/callback')->assertRedirect('/battlefield');

    $existing->refresh();
    expect(User::count())->toBe(1)
        ->and($existing->hook_token)->toBe($originalToken)
        ->and($existing->slack_handle)->toBe('new-handle')
        ->and($existing->avatar_url)->toBe('https://avatar/new.png')
        ->and(auth()->id())->toBe($existing->id);

    expect(session('hook_token_plain'))->toBeNull();
});
