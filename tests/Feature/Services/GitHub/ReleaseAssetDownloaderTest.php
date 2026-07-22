<?php

use App\Services\GitHub\ReleaseAssetDownloader;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['github.token' => 'ghp_test', 'github.cli_repo' => 'acme/slayer-cli']);
});

test('returns the asset bytes', function () {
    Http::fake(['api.github.com/repos/acme/slayer-cli/releases/assets/22' => Http::response('WHEEL', 200)]);

    expect(app(ReleaseAssetDownloader::class)->download(22))->toBe('WHEEL');
});

test('returns null when the asset download fails', function () {
    Http::fake(['api.github.com/*' => Http::response('nope', 500)]);

    expect(app(ReleaseAssetDownloader::class)->download(22))->toBeNull();
});

test('returns null when the transport throws', function () {
    Http::fake(fn () => throw new RuntimeException('network down'));

    expect(app(ReleaseAssetDownloader::class)->download(22))->toBeNull();
});
