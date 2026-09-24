<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
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
 * @param  array<string, Response>  $overrides  per-endpoint Http::response() overrides keyed by 'token'|'usage'|'profile'|'messages'
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
        config('token_slayer.anthropic.messages_endpoint') => $overrides['messages'] ?? Http::response(['id' => 'msg_fake', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'max_tokens'], 200, ['Content-Type' => 'application/json']),
    ]);
}

/**
 * Attach a person to an account as a tracked member and give them a run of
 * daily usage on it.
 *
 * @param  Account  $account  the account to join and spend on
 * @param  User  $user  the person
 * @param  string  $firstDay  the first day of the run
 * @param  int  $days  how many consecutive days
 * @param  int  $tokensPerDay  tokens on each day
 * @return void
 */
function livesOn(Account $account, User $user, string $firstDay, int $days, int $tokensPerDay): void
{
    $account->users()->syncWithoutDetaching([$user->id => ['status' => MembershipStatus::Tracked->value]]);

    // Backdated with the usage: a seat comes into being when somebody is put
    // on it, and a fixture that stamps every membership "now" makes every
    // seat look freshly granted.
    DB::table('account_user')
        ->where('user_id', $user->id)
        ->where('account_id', $account->id)
        ->update(['created_at' => Carbon::parse($firstDay)]);

    foreach (range(0, $days - 1) as $offset) {
        Event::factory()->for($account)->for($user)->create([
            'tokens' => $tokensPerDay,
            'created_at' => Carbon::parse($firstDay)->addDays($offset),
        ]);
    }
}

/**
 * Backs the `Redis` facade with a tiny in-memory fake — a plain keyed array
 * simulating SETEX/EXPIRE/EXISTS/DEL/KEYS, plus SADD/SMEMBERS against one
 * reserved entry per Redis SET (its value held as an array of members,
 * distinguishing it from a plain presence key's scalar value) — for tests
 * that exercise SubagentCountCache (see its own docblock: no real Redis
 * server is available in this environment). Mutating `$store` directly
 * between calls (e.g. unsetting a key, or replacing it with `[]`) simulates
 * Redis expiring a key on its own, since nothing here implements real TTL
 * countdown.
 *
 * @param  array<string, mixed>  $store  passed by reference so callers can seed/inspect/mutate the fake store directly
 * @return void
 */
function fakeRedis(array &$store): void
{
    Redis::shouldReceive('setex')->andReturnUsing(function (string $key, int $ttl, mixed $value) use (&$store) {
        $store[$key] = $value;

        return true;
    });
    Redis::shouldReceive('expire')->andReturnUsing(function (string $key, int $ttl) use (&$store) {
        return array_key_exists($key, $store);
    });
    Redis::shouldReceive('exists')->andReturnUsing(function (string $key) use (&$store) {
        return array_key_exists($key, $store) ? 1 : 0;
    });
    Redis::shouldReceive('del')->andReturnUsing(function (string $key) use (&$store) {
        $existed = array_key_exists($key, $store);
        unset($store[$key]);

        return $existed ? 1 : 0;
    });
    // Real phpredis returns KEYS results WITH the client's configured
    // REDIS_PREFIX still on them (unlike GET/SET-style commands, which take
    // and return unprefixed keys transparently) — caught live 2026-09-24 on
    // prod: feeding a KEYS-returned string straight back into del()/exists()
    // double-prefixes it, so it silently matches nothing. Prepending a fake
    // prefix here forces SubagentCountCache to actually strip it, the same
    // way it must against real Redis.
    Redis::shouldReceive('keys')->andReturnUsing(function (string $pattern) use (&$store) {
        $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';
        $matching = array_values(array_filter(array_keys($store), fn ($key) => preg_match($regex, $key) === 1 && ! is_array($store[$key])));

        return array_map(fn ($key) => 'fake-prefix-'.$key, $matching);
    });
    Redis::shouldReceive('sadd')->andReturnUsing(function (string $key, mixed ...$members) use (&$store) {
        $store[$key] ??= [];
        $added = 0;
        foreach ($members as $member) {
            if (! in_array($member, $store[$key], false)) {
                $store[$key][] = $member;
                $added++;
            }
        }

        return $added;
    });
    Redis::shouldReceive('smembers')->andReturnUsing(function (string $key) use (&$store) {
        return $store[$key] ?? [];
    });
}
