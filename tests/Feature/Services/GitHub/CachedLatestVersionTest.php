<?php

use App\Services\GitHub\CachedLatestVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config(['github.token' => 'ghp_test', 'github.cli_repo' => 'acme/slayer-cli']);
});

/**
 * Fake a successful /releases/latest response carrying the given tag.
 */
function fakeRelease(string $tag): void
{
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => $tag,
        'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']],
    ], 200)]);
}

test('returns the resolved version', function () {
    fakeRelease('v1.0.4');

    expect(app(CachedLatestVersion::class)->get())->toBe('1.0.4');
});

test('hits github only once across repeated reads', function () {
    fakeRelease('v1.0.4');

    app(CachedLatestVersion::class)->get();
    app(CachedLatestVersion::class)->get();
    app(CachedLatestVersion::class)->get();

    Http::assertSentCount(1);
});

test('serves the cached version after the upstream starts failing', function () {
    fakeRelease('v1.0.4');
    app(CachedLatestVersion::class)->get();

    Http::fake(['api.github.com/*' => Http::response(['message' => 'down'], 500)]);

    expect(app(CachedLatestVersion::class)->get())->toBe('1.0.4');
});

test('returns null when the version is unknown', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'down'], 500)]);

    expect(app(CachedLatestVersion::class)->get())->toBeNull();
});

test('caches the failure so an outage does not re-hit github on every page load', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'down'], 500)]);

    app(CachedLatestVersion::class)->get();
    app(CachedLatestVersion::class)->get();

    // The sentinel is the whole point: Cache::remember would not store null and
    // every profile load would pay the full 8s timeout during an outage.
    Http::assertSentCount(1);
});
