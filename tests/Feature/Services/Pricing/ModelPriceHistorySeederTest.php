<?php

use App\Models\ModelPrice;
use App\Services\Pricing\ModelPriceHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('inserts the dated history once', function () {
    $seeder = app(ModelPriceHistorySeeder::class);

    expect($seeder->seed())->toBe(2)
        ->and($seeder->seed())->toBe(0)
        ->and(ModelPrice::where('model', 'claude-sonnet-5-5')->orderBy('effective_from')->pluck('cache_read_per_mtok')->all())->toBe([0.2, 0.1])
        ->and(ModelPrice::whereNotNull('source_sha256')->count())->toBe(0);
});

it('keeps every history price within the rules the feed parser applies', function () {
    $rows = json_decode(file_get_contents(database_path('data/model_price_history.json')), true, 8, JSON_THROW_ON_ERROR);

    foreach ($rows as $row) {
        expect($row['provider'])->toBeIn(['anthropic', 'openai'])
            ->and($row['effective_from'])->toMatch('/\A\d{4}-\d{2}-\d{2}\z/');

        foreach (['input', 'output', 'cache_read', 'cache_write_5m', 'cache_write_1h'] as $field) {
            expect($row[$field])->toBeNumeric()->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(ModelPrice::MAX_PRICE_PER_MTOK);
        }
    }
});
