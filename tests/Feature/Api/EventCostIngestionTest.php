<?php

use App\Models\Boss;
use App\Models\Event;
use App\Models\MissingModel;
use App\Models\ModelPrice;
use App\Models\User;
use App\Services\Pricing\ModelPriceLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = [];
    fakeRedis($this->store);
    $this->user = User::factory()->create(['hook_token' => hash('sha256', 'tok')]);
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000_000, 'current_hp' => 1_000_000]);
    Cache::flush();
});

/**
 * Post a complete Stop turn as a hook would.
 *
 * @param  array<string, mixed>  $overrides  payload keys to replace
 * @param  string  $provider  the `?provider=` query value
 * @return void
 */
function postCompleteStop(array $overrides = [], string $provider = 'claude-code'): void
{
    test()->withHeader('Authorization', 'Bearer tok')
        ->postJson("/api/events?provider={$provider}", array_merge([
            'hook_event_name' => 'Stop',
            'session_id' => 'sess-1',
            'tokens' => 3000,
            'models' => ['claude-sonnet-5-5' => 3000],
            'input_tokens' => 1000,
            'cache_creation_input_tokens' => 10000,
            'cache_read_input_tokens' => 200000,
            'hook_version' => '7',
        ], $overrides))
        ->assertCreated();
}

it('stores the cost of a complete turn with a priced model', function () {
    ModelPrice::factory()->create();

    postCompleteStop();

    expect(Event::sole()->cost_micro_usd)->toBe(92000);
});

it('stores no cost and records the model when a complete turn has no price', function () {
    postCompleteStop();

    expect(Event::sole()->cost_micro_usd)->toBeNull()
        ->and(MissingModel::pluck('model')->all())->toBe(['claude-sonnet-5-5']);
});

it('stores no cost and records nothing for an incomplete turn', function () {
    ModelPrice::factory()->create();

    postCompleteStop(['models' => null, 'input_tokens' => 0, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0]);

    expect(Event::sole()->cost_micro_usd)->toBeNull()
        ->and(MissingModel::count())->toBe(0);
});

it('never prices a provider that sends output only', function () {
    postCompleteStop([], 'claude-ai');

    expect(Event::sole()->cost_micro_usd)->toBeNull()
        ->and(MissingModel::count())->toBe(0);
});

it('ignores a cost sent by the client', function () {
    postCompleteStop(['cost_micro_usd' => 1, 'cost' => 1]);

    expect(Event::sole()->cost_micro_usd)->toBeNull();
});

it('still stores the event when the price lookup fails, and queues the model for the fill', function () {
    Exceptions::fake();
    $this->mock(ModelPriceLookup::class)->shouldReceive('current')->andThrow(new RuntimeException('cache down'));

    postCompleteStop();

    expect(Event::sole()->cost_micro_usd)->toBeNull()
        ->and(MissingModel::pluck('model')->all())->toBe(['claude-sonnet-5-5']);
    Exceptions::assertReported(RuntimeException::class);
});
