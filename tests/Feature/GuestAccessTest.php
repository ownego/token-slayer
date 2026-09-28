<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a visitor without a Slack login is sent to log in before seeing any team page', function (string $path) {
    // the battlefield, the kill history and teammates' avatars were open to
    // anyone with the link
    $this->get($path)->assertRedirect(route('slack.login'));
})->with([
    'battlefield' => '/battlefield',
    'history' => '/history',
    'avatar' => fn () => '/avatars/'.User::factory()->create(['avatar_url' => 'https://avatars.slack-edge.com/x_512.jpg'])->id,
]);

test('an avatar is only cached by the viewer\'s own browser, never a shared cache that would hand it to a guest', function () {
    Http::fake(['*' => Http::response('IMG', 200, ['Content-Type' => 'image/jpeg'])]);
    $viewer = User::factory()->create();
    $mate = User::factory()->create(['avatar_url' => 'https://avatars.slack-edge.com/x_512.jpg']);

    $this->actingAs($viewer)->get(route('avatar', $mate))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=604800, private');
});
