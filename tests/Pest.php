<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Fake the three Anthropic OAuth endpoints (token, usage, profile) with the
 * real captured fixtures in tests/fixtures/anthropic/, so AnthropicOAuthClient
 * tests never touch the network. Calls Http::preventStrayRequests() so an
 * unfaked call fails loudly instead of hitting the real API.
 *
 * Pass per-key overrides to simulate failures, e.g.
 * fakeAnthropic(['token' => Http::response('', 429)]).
 *
 * @param  array<string, Response>  $overrides  per-endpoint Http::response() overrides keyed by 'token'|'usage'|'profile'
 * @return void
 */
function fakeAnthropic(array $overrides = []): void
{
    Http::preventStrayRequests();

    // Fixture bodies are served as raw JSON text (not decode+re-encode) so
    // literal float values like utilization: 0.0 survive byte-for-byte —
    // re-encoding through PHP's json_encode can collapse 0.0 to the int 0.
    $fixture = fn (string $name): string => file_get_contents(base_path("tests/fixtures/anthropic/{$name}.json"));

    Http::fake([
        config('token_slayer.anthropic.token_endpoint') => $overrides['token'] ?? Http::response($fixture('token'), 200, ['Content-Type' => 'application/json']),
        config('token_slayer.anthropic.usage_endpoint') => $overrides['usage'] ?? Http::response($fixture('usage'), 200, ['Content-Type' => 'application/json']),
        config('token_slayer.anthropic.profile_endpoint') => $overrides['profile'] ?? Http::response($fixture('profile'), 200, ['Content-Type' => 'application/json']),
    ]);
}
