<?php

use App\Services\SlayerCliWheelFetcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'token_slayer.slayer_cli.github_repo' => 'ownego/token-slayer-cli',
        'token_slayer.slayer_cli.github_token' => 'fake-token',
        'token_slayer.slayer_cli.wheel_asset_name' => 'slayer_cli-latest.whl',
    ]);
});

test('fetches the wheel bytes via an authenticated GitHub API call', function () {
    Http::fake([
        'api.github.com/repos/ownego/token-slayer-cli/releases/latest' => Http::response([
            'assets' => [
                ['name' => 'slayer_cli-1.0.0-py3-none-any.whl', 'url' => 'https://api.github.com/repos/ownego/token-slayer-cli/releases/assets/1'],
                ['name' => 'slayer_cli-latest.whl', 'url' => 'https://api.github.com/repos/ownego/token-slayer-cli/releases/assets/2'],
            ],
        ], 200),
        'api.github.com/repos/ownego/token-slayer-cli/releases/assets/2' => Http::response('FAKE-WHEEL-BYTES', 200),
    ]);

    $bytes = app(SlayerCliWheelFetcher::class)->fetch();

    expect($bytes)->toBe('FAKE-WHEEL-BYTES');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/repos/ownego/token-slayer-cli/releases/latest'
        && $request->hasHeader('Authorization', 'Bearer fake-token'));

    Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/repos/ownego/token-slayer-cli/releases/assets/2'
        && $request->hasHeader('Authorization', 'Bearer fake-token')
        && $request->hasHeader('Accept', 'application/octet-stream'));
});

test('returns null when the repo or token is not configured', function () {
    config(['token_slayer.slayer_cli.github_repo' => '']);

    expect(app(SlayerCliWheelFetcher::class)->fetch())->toBeNull();

    Http::fake();
    Http::assertNothingSent();
});

test('returns null when the release lookup fails', function () {
    Http::fake([
        'api.github.com/repos/ownego/token-slayer-cli/releases/latest' => Http::response('', 500),
    ]);

    expect(app(SlayerCliWheelFetcher::class)->fetch())->toBeNull();
});

test('returns null when no asset matches the configured wheel name', function () {
    Http::fake([
        'api.github.com/repos/ownego/token-slayer-cli/releases/latest' => Http::response([
            'assets' => [
                ['name' => 'something-else.whl', 'url' => 'https://api.github.com/repos/ownego/token-slayer-cli/releases/assets/9'],
            ],
        ], 200),
    ]);

    expect(app(SlayerCliWheelFetcher::class)->fetch())->toBeNull();
});

test('returns null when the asset download fails', function () {
    Http::fake([
        'api.github.com/repos/ownego/token-slayer-cli/releases/latest' => Http::response([
            'assets' => [
                ['name' => 'slayer_cli-latest.whl', 'url' => 'https://api.github.com/repos/ownego/token-slayer-cli/releases/assets/2'],
            ],
        ], 200),
        'api.github.com/repos/ownego/token-slayer-cli/releases/assets/2' => Http::response('', 404),
    ]);

    expect(app(SlayerCliWheelFetcher::class)->fetch())->toBeNull();
});
