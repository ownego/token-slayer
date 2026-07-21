<?php

use App\Services\SlayerWheelProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['github.token' => 'ghp_test', 'github.cli_repo' => 'acme/slayer-cli']);
});

test('returns the wheel bytes for the latest release', function () {
    Http::fake([
        'api.github.com/repos/*/releases/latest' => Http::response([
            'tag_name' => 'v1.0.4',
            'assets' => [['id' => 22, 'name' => 'slayer_cli-latest.whl']],
        ], 200),
        'api.github.com/repos/*/releases/assets/22' => Http::response('WHEEL', 200),
    ]);

    expect(app(SlayerWheelProvider::class)->bytes())->toBe('WHEEL');
});

test('returns null when the release cannot be resolved', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

    expect(app(SlayerWheelProvider::class)->bytes())->toBeNull();
});

test('returns null when the asset download fails', function () {
    Http::fake([
        'api.github.com/repos/*/releases/latest' => Http::response([
            'tag_name' => 'v1.0.4',
            'assets' => [['id' => 22, 'name' => 'slayer_cli-latest.whl']],
        ], 200),
        'api.github.com/repos/*/releases/assets/22' => Http::response('nope', 500),
    ]);

    expect(app(SlayerWheelProvider::class)->bytes())->toBeNull();
});
