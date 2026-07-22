<?php

use App\Services\GitHub\GitHubClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'github.token' => 'ghp_test',
        'github.cli_repo' => 'acme/slayer-cli',
        'github.api_url' => 'https://api.github.com',
        'github.api_version' => '2026-03-10',
        'github.user_agent' => 'token-slayer-server',
        'github.timeout' => 8,
        'github.download_timeout' => 20,
    ]);
});

test('json requests carry auth, the pinned api version and a custom user agent', function () {
    Http::fake(['api.github.com/*' => Http::response([], 200)]);

    app(GitHubClient::class)->json()->get('/repos/acme/slayer-cli/releases/latest');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/repos/acme/slayer-cli/releases/latest'
        && $request->hasHeader('Authorization', 'Bearer ghp_test')
        && $request->hasHeader('Accept', 'application/vnd.github+json')
        && $request->hasHeader('X-GitHub-Api-Version', '2026-03-10')
        && $request->hasHeader('User-Agent', 'token-slayer-server'));
});

test('binary requests ask for octet-stream so github returns asset bytes', function () {
    Http::fake(['api.github.com/*' => Http::response('BYTES', 200)]);

    app(GitHubClient::class)->binary()->get('/repos/acme/slayer-cli/releases/assets/22');

    Http::assertSent(fn ($request) => $request->hasHeader('Accept', 'application/octet-stream')
        && $request->hasHeader('Authorization', 'Bearer ghp_test'));
});

test('reports whether the credential and repo are configured', function () {
    expect(app(GitHubClient::class)->isConfigured())->toBeTrue();

    config(['github.token' => '', 'github.cli_repo' => '']);

    expect(app(GitHubClient::class)->isConfigured())->toBeFalse();
});

test('exposes the configured repo slug', function () {
    expect(app(GitHubClient::class)->repo())->toBe('acme/slayer-cli');
});
