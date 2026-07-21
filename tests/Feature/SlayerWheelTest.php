<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['github.token' => 'ghp_test', 'github.cli_repo' => 'acme/slayer-cli']);
});

/**
 * Create a user whose stored hook token hashes from the returned plaintext.
 */
function userWithHookToken(): string
{
    $plain = 'tok_'.str_repeat('a', 44);

    User::factory()->create(['hook_token' => hash('sha256', $plain)]);

    return $plain;
}

test('rejects an anonymous wheel download', function () {
    $this->get('/dist/slayer_cli-latest.whl')->assertStatus(401);
});

test('relays the wheel bytes for a valid hook token', function () {
    $plain = userWithHookToken();

    Http::fake([
        'api.github.com/repos/*/releases/latest' => Http::response([
            'tag_name' => 'v1.0.4',
            'assets' => [['id' => 22, 'name' => 'slayer_cli-latest.whl']],
        ], 200),
        'api.github.com/repos/*/releases/assets/22' => Http::response('WHEEL', 200),
    ]);

    $response = $this->withToken($plain)->get('/dist/slayer_cli-latest.whl');

    $response->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="slayer_cli-latest.whl"');

    expect($response->getContent())->toBe('WHEEL');
});

test('returns a generic 503 that never reveals our own credential failed', function () {
    $plain = userWithHookToken();

    Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

    $response = $this->withToken($plain)->get('/dist/slayer_cli-latest.whl');

    // The install script branches on the status code and discards the body, so
    // 503 is the contract. The body is Laravel's stock errors::503 page ("Service
    // Unavailable") — it must never carry the upstream failure's detail. We
    // assert on what we control, not on the page's vendored CSS (which happens
    // to cite github.com/necolas/normalize.css, hence no bare 'github' check).
    $response->assertStatus(503);

    expect(strtolower($response->getContent()))
        ->not->toContain('ghp_test')             // never the PAT
        ->not->toContain('bad credentials')      // never the upstream's wording
        ->not->toContain('api.github.com');      // never the upstream host
});

test('returns a generic 503 when the asset download fails', function () {
    $plain = userWithHookToken();

    Http::fake([
        'api.github.com/repos/*/releases/latest' => Http::response([
            'tag_name' => 'v1.0.4',
            'assets' => [['id' => 22, 'name' => 'slayer_cli-latest.whl']],
        ], 200),
        'api.github.com/repos/*/releases/assets/22' => Http::response('nope', 500),
    ]);

    $this->withToken($plain)->get('/dist/slayer_cli-latest.whl')->assertStatus(503);
});
