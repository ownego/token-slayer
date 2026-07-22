<?php

use App\Exceptions\ReleaseResolutionException;
use App\Services\GitHub\ReleaseResolver;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['github.token' => 'ghp_test', 'github.cli_repo' => 'acme/slayer-cli']);
});

test('returns the version and wheel asset from the latest release', function () {
    Http::fake([
        'api.github.com/*' => Http::response([
            'tag_name' => 'v1.0.4',
            'assets' => [
                ['id' => 11, 'name' => 'slayer_cli-1.0.4-py3-none-any.whl'],
                ['id' => 22, 'name' => 'slayer_cli-latest.whl'],
            ],
        ], 200),
    ]);

    $release = app(ReleaseResolver::class)->latest();

    expect($release['version'])->toBe('1.0.4')            // leading 'v' stripped
        ->and($release['asset_id'])->toBe(22)             // prefers the -latest alias
        ->and($release['asset_name'])->toBe('slayer_cli-latest.whl');
});

test('falls back to any wheel asset when there is no latest alias', function () {
    Http::fake([
        'api.github.com/*' => Http::response([
            'tag_name' => '1.0.5',
            'assets' => [['id' => 33, 'name' => 'slayer_cli-1.0.5-py3-none-any.whl']],
        ], 200),
    ]);

    expect(app(ReleaseResolver::class)->latest()['asset_id'])->toBe(33);
});

test('returns null on any upstream failure', function (int $status, array $body) {
    Http::fake(['api.github.com/*' => Http::response($body, $status)]);

    expect(app(ReleaseResolver::class)->latest())->toBeNull();
})->with([
    '401 bad token' => [401, ['message' => 'Bad credentials']],
    '404 no release' => [404, ['message' => 'Not Found']],
    'no wheel asset' => [200, ['tag_name' => 'v1.0.6', 'assets' => [['id' => 1, 'name' => 'notes.txt']]]],
    'missing tag' => [200, ['assets' => []]],
]);

test('returns null and does not throw when github is unreachable', function () {
    Http::fake(fn () => throw new RuntimeException('network down'));

    expect(app(ReleaseResolver::class)->latest())->toBeNull();
});

test('does not call github when the repo or token is unconfigured', function () {
    config(['github.token' => '', 'github.cli_repo' => '']);
    Http::fake();

    expect(app(ReleaseResolver::class)->latest())->toBeNull();

    Http::assertNothingSent();
});

test('reports a named exception so an expired PAT stays diagnosable', function () {
    Exceptions::fake();
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

    app(ReleaseResolver::class)->latest();

    Exceptions::assertReported(fn (ReleaseResolutionException $e) => $e->reason === 'http_error');
});

test('never puts the credential in the reported exception', function () {
    Exceptions::fake();
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

    app(ReleaseResolver::class)->latest();

    Exceptions::assertReported(fn (ReleaseResolutionException $e) => ! str_contains($e->getMessage(), 'ghp_test'));
});
