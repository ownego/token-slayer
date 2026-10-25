<?php

use App\Models\Event;
use App\Models\MissingModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->feedUrl = config('token_slayer.model_prices.feed_url');
    $this->travelTo('2026-10-24 10:00:00');
    $this->user = User::factory()->create();
});

/**
 * A stored turn with only cache-read tokens, so its cost shows which
 * cache-read price it got.
 *
 * @param  string  $model  the turn's model
 * @param  string  $createdAt  when the turn happened
 * @param  int  $cacheRead  cache-read tokens (0 makes the turn incomplete)
 * @return Event
 */
function oldTurn(string $model, string $createdAt, int $cacheRead = 1_000_000): Event
{
    return Event::factory()->create([
        'user_id' => test()->user->id,
        'model' => $model,
        'tokens' => 0,
        'input_tokens' => 0,
        'cache_creation_input_tokens' => 0,
        'cache_read_input_tokens' => $cacheRead,
        'created_at' => $createdAt,
    ]);
}

it('lists the models it would queue and writes nothing on a dry run', function () {
    Http::fake();
    oldTurn('claude-sonnet-5-5', '2026-10-01 12:00:00');
    oldTurn('claude-sonnet-5-5', '2026-10-02 12:00:00');

    $this->artisan('event-costs:backfill --dry-run')
        ->expectsOutputToContain('claude-sonnet-5-5: 2 unpriced event(s)')
        ->assertSuccessful();

    expect(MissingModel::count())->toBe(0);
    Http::assertNothingSent();
});

it('prices old events at the price of their own day', function () {
    Http::fake([$this->feedUrl => Http::response(file_get_contents(base_path('tests/fixtures/litellm/model_prices.json')), 200)]);
    $before = oldTurn('claude-sonnet-5-5', '2026-10-01 12:00:00');
    $after = oldTurn('claude-sonnet-5-5', '2026-10-10 12:00:00');
    $incomplete = oldTurn('claude-sonnet-5-5', '2026-10-10 12:00:00', cacheRead: 0);
    $unknown = oldTurn('gpt-unknown', '2026-10-10 12:00:00');

    $this->artisan('event-costs:backfill --force')->assertSuccessful();

    expect($before->fresh()->cost_micro_usd)->toBe(200_000)
        ->and($after->fresh()->cost_micro_usd)->toBe(100_000)
        ->and($incomplete->fresh()->cost_micro_usd)->toBeNull()
        ->and($unknown->fresh()->cost_micro_usd)->toBeNull()
        ->and(MissingModel::pluck('model')->all())->toContain('gpt-unknown');
});

it('does not rewrite an event that already has a cost', function () {
    Http::fake([$this->feedUrl => Http::response(file_get_contents(base_path('tests/fixtures/litellm/model_prices.json')), 200)]);
    $priced = oldTurn('claude-sonnet-5-5', '2026-10-01 12:00:00');
    $priced->forceFill(['cost_micro_usd' => 7])->save();

    $this->artisan('event-costs:backfill --force')->assertSuccessful();

    expect($priced->fresh()->cost_micro_usd)->toBe(7);
});

it('aborts when the confirmation is declined', function () {
    Http::fake();
    oldTurn('claude-sonnet-5-5', '2026-10-01 12:00:00');

    $this->artisan('event-costs:backfill')
        ->expectsConfirmation('Queue 1 model(s) and run the price sync?', 'no')
        ->expectsOutputToContain('Aborted')
        ->assertFailed();

    expect(MissingModel::count())->toBe(0);
});

it('fails on a feed error and leaves the models queued for the hourly run', function () {
    Http::fake([$this->feedUrl => Http::response('', 500)]);
    oldTurn('claude-sonnet-5-5', '2026-10-01 12:00:00');

    $this->artisan('event-costs:backfill --force')->assertFailed();

    expect(MissingModel::pluck('model')->all())->toBe(['claude-sonnet-5-5']);
});
