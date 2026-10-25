<?php

use App\Exceptions\ModelPriceFeedException;
use App\Models\MissingModel;
use App\Models\ModelPrice;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->feedUrl = config('token_slayer.model_prices.feed_url');
    $this->body = file_get_contents(base_path('tests/fixtures/litellm/model_prices.json'));
});

it('syncs prices and reports the result', function () {
    Http::fake([$this->feedUrl => Http::response($this->body, 200)]);

    $this->artisan('model-prices:sync')->assertSuccessful();

    expect(ModelPrice::count())->toBe(2);
});

it('makes no request on the hourly run when no recent model is missing', function () {
    Http::fake();
    MissingModel::factory()->create(['first_seen_at' => now()->subHours(49)]);

    $this->artisan('model-prices:sync --if-missing')
        ->expectsOutputToContain('No missing models')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('fetches on the hourly run while a model went missing within the window', function () {
    Http::fake([$this->feedUrl => Http::response($this->body, 200)]);
    MissingModel::factory()->create(['model' => 'gpt-6-sol', 'first_seen_at' => now()->subHours(47)]);

    $this->artisan('model-prices:sync --if-missing')->assertSuccessful();

    expect(ModelPrice::where('model', 'gpt-6-sol')->exists())->toBeTrue();
});

it('still asks the daily run for a model missing longer than the window', function () {
    Http::fake([$this->feedUrl => Http::response($this->body, 200)]);
    MissingModel::factory()->create(['model' => 'gpt-6-sol', 'first_seen_at' => now()->subHours(49)]);

    $this->artisan('model-prices:sync')->assertSuccessful();

    expect(ModelPrice::where('model', 'gpt-6-sol')->exists())->toBeTrue();
});

it('reports a feed failure and exits with failure', function () {
    Exceptions::fake();
    Http::fake([$this->feedUrl => Http::response('', 500)]);

    $this->artisan('model-prices:sync')->assertFailed();

    Exceptions::assertReported(ModelPriceFeedException::class);
});

it('is scheduled daily and hourly, never overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'model-prices:sync'));

    $daily = $events->first(fn ($event) => ! str_contains($event->command, '--if-missing'));
    $hourly = $events->first(fn ($event) => str_contains($event->command, '--if-missing'));

    expect($daily->expression)->toBe('0 6 * * *')
        ->and($daily->timezone)->toBe('Asia/Ho_Chi_Minh')
        ->and($daily->withoutOverlapping)->toBeTrue()
        ->and($hourly->expression)->toBe('0 * * * *')
        ->and($hourly->withoutOverlapping)->toBeTrue();
});
