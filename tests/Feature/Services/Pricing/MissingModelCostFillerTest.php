<?php

use App\Models\Event;
use App\Models\MissingModel;
use App\Models\ModelPrice;
use App\Models\User;
use App\Services\Pricing\MissingModelCostFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-24 10:00:00');
    ModelPrice::factory()->create(['effective_from' => '2026-09-01', 'cache_read_per_mtok' => 0.20]);
    ModelPrice::factory()->create(['effective_from' => '2026-10-07', 'cache_read_per_mtok' => 0.10]);
});

/**
 * A stored claude-code turn with only cache-read tokens, so its cost shows
 * which cache-read price it got.
 *
 * @param  string  $createdAt  when the turn happened
 * @param  bool  $complete  whether the hook sent input/cache tokens
 * @param  string  $model  the turn's model
 * @return Event
 */
function storedTurn(string $createdAt, bool $complete = true, string $model = 'claude-sonnet-5-5'): Event
{
    return Event::factory()->create([
        'user_id' => User::factory(),
        'model' => $model,
        'tokens' => 0,
        'input_tokens' => 0,
        'cache_creation_input_tokens' => 0,
        'cache_read_input_tokens' => $complete ? 1_000_000 : 0,
        'created_at' => $createdAt,
    ]);
}

it('prices a now-priced missing model with the price of each event day', function () {
    MissingModel::factory()->create(['last_seen_at' => now()->subMinute()]);
    $before = storedTurn('2026-10-01 12:00:00');
    $after = storedTurn('2026-10-10 12:00:00');

    $filled = app(MissingModelCostFiller::class)->fill();

    expect($filled)->toBe(2)
        ->and($before->fresh()->cost_micro_usd)->toBe(200_000)
        ->and($after->fresh()->cost_micro_usd)->toBe(100_000)
        ->and(MissingModel::count())->toBe(0);
});

it('leaves incomplete events unpriced', function () {
    MissingModel::factory()->create(['last_seen_at' => now()->subMinute()]);
    $incomplete = storedTurn('2026-10-10 12:00:00', complete: false);

    app(MissingModelCostFiller::class)->fill();

    expect($incomplete->fresh()->cost_micro_usd)->toBeNull();
});

it('keeps a model that still has no price', function () {
    MissingModel::factory()->create(['model' => 'gpt-unknown', 'last_seen_at' => now()->subMinute()]);
    $turn = storedTurn('2026-10-10 12:00:00', model: 'gpt-unknown');

    expect(app(MissingModelCostFiller::class)->fill())->toBe(0)
        ->and($turn->fresh()->cost_micro_usd)->toBeNull()
        ->and(MissingModel::count())->toBe(1);
});

it('keeps a row an event touched after the fill started', function () {
    MissingModel::factory()->create(['last_seen_at' => now()->addSecond()]);

    app(MissingModelCostFiller::class)->fill();

    expect(MissingModel::count())->toBe(1);
});

it('keeps a row touched in the same second the fill started', function () {
    MissingModel::factory()->create(['last_seen_at' => now()]);

    app(MissingModelCostFiller::class)->fill();

    expect(MissingModel::count())->toBe(1);
});
