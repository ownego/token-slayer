<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

test('proxies a slack avatar with CORS-friendly headers', function () {
    Http::fake([
        'avatars.slack-edge.com/*' => Http::response('FAKE_JPEG_BYTES', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $user = User::factory()->create([
        'avatar_url' => 'https://avatars.slack-edge.com/example.jpg',
    ]);

    $response = $this->get(route('avatar', $user));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeader('Cache-Control', 'max-age=604800, public');

    expect($response->getContent())->toBe('FAKE_JPEG_BYTES');
});

test('caches the upstream response so the second request does not refetch', function () {
    Http::fake([
        'avatars.slack-edge.com/*' => Http::response('FAKE_JPEG_BYTES', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $user = User::factory()->create([
        'avatar_url' => 'https://avatars.slack-edge.com/example.jpg',
    ]);

    $this->get(route('avatar', $user))->assertOk();
    $this->get(route('avatar', $user))->assertOk();

    Http::assertSentCount(1);
});

test('returns 404 when the user has no avatar url', function () {
    $user = User::factory()->create(['avatar_url' => null]);

    $this->get(route('avatar', $user))->assertNotFound();
});

test('returns 404 when the upstream fetch fails', function () {
    Http::fake([
        'avatars.slack-edge.com/*' => Http::response('not found', 404),
    ]);

    $user = User::factory()->create([
        'avatar_url' => 'https://avatars.slack-edge.com/missing.jpg',
    ]);

    $this->get(route('avatar', $user))->assertNotFound();
});

test('returns 404, not a 500, when the avatar host can\'t even be reached', function () {
    // A DNS failure/timeout throws Illuminate\Http\Client\ConnectionException
    // rather than returning a response Http::response()->successful() can
    // check — caught live on staging by an old fake-test user whose seeded
    // avatar_url pointed at a non-resolvable host.
    Http::fake([
        'avatars.example/*' => fn () => throw new ConnectionException('Could not resolve host'),
    ]);

    $user = User::factory()->create([
        'avatar_url' => 'https://avatars.example/unreachable.png',
    ]);

    $this->get(route('avatar', $user))->assertNotFound();
});

test('fetches Slack\'s 192px variant instead of the stored 512px original', function () {
    // The avatar shows at 26-40px; the 512px PNGs measured up to ~600 KB each on prod.
    Http::fake(['avatars.slack-edge.com/*' => Http::response('SMALL', 200, ['Content-Type' => 'image/png'])]);
    $user = User::factory()->create(['avatar_url' => 'https://avatars.slack-edge.com/2026-07-13/1156_4da0_512.png']);

    $this->get(route('avatar', $user))->assertOk();

    Http::assertSent(fn ($request) => $request->url() === 'https://avatars.slack-edge.com/2026-07-13/1156_4da0_192.png');
    Http::assertSentCount(1);
});

test('falls back to the stored URL when Slack has no 192px variant', function () {
    Http::fake([
        'avatars.slack-edge.com/*_192.png' => Http::response('', 404),
        'avatars.slack-edge.com/*' => Http::response('ORIGINAL', 200, ['Content-Type' => 'image/png']),
    ]);
    $user = User::factory()->create(['avatar_url' => 'https://avatars.slack-edge.com/2026-07-13/1156_4da0_512.png']);

    expect($this->get(route('avatar', $user))->assertOk()->getContent())->toBe('ORIGINAL');
});
