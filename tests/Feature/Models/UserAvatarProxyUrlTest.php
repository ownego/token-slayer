<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the avatar URL is versioned by the Slack image, so a week of browser cache never shows a stale face', function () {
    $user = User::factory()->create(['avatar_url' => 'https://avatars.slack-edge.com/a_512.png']);
    $before = $user->avatarProxyUrl();

    $user->update(['avatar_url' => 'https://avatars.slack-edge.com/b_512.png']);

    expect($before)->toStartWith(route('avatar', $user).'?v=')
        ->and($user->avatarProxyUrl())->not->toBe($before)
        ->and($user->avatarProxyUrl())->toBe($user->fresh()->avatarProxyUrl());
});

test('a user without a Slack avatar has no avatar URL', function () {
    expect(User::factory()->create(['avatar_url' => null])->avatarProxyUrl())->toBeNull();
});
