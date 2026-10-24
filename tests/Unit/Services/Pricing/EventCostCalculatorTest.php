<?php

use App\Models\ModelPrice;
use App\Services\Pricing\EventCostCalculator;
use App\Services\Pricing\PricedUsage;

/**
 * Claude Sonnet 5.5 list prices per MTok: in, out, cache read, 5m write, 1h write.
 *
 * @return array<string, float|string>
 */
function sonnet55Price(): array
{
    return [
        'model' => 'claude-sonnet-5-5',
        'provider' => 'anthropic',
        'input_per_mtok' => 2,
        'output_per_mtok' => 10,
        'cache_read_per_mtok' => 0.10,
        'cache_write_5m_per_mtok' => 2.5,
        'cache_write_1h_per_mtok' => 4,
    ];
}

/**
 * Codex gpt-6-sol list prices per MTok; OpenAI bills no cache writes.
 *
 * @return array<string, float|string|null>
 */
function gpt6SolPrice(): array
{
    return [
        'model' => 'gpt-6-sol',
        'provider' => 'openai',
        'input_per_mtok' => 2,
        'output_per_mtok' => 10,
        'cache_read_per_mtok' => 0.20,
        'cache_write_5m_per_mtok' => 0,
        'cache_write_1h_per_mtok' => null,
    ];
}

it('prices a turn in micro-dollars', function (PricedUsage $usage, array $price, int $expected) {
    $row = new ModelPrice($price);

    expect((new EventCostCalculator)->costMicroUsd($usage, $row))->toBe($expected);
})->with([
    'claude main thread writes at the 1h rate' => fn () => [new PricedUsage('claude-code', 'claude-sonnet-5-5', 3000, 1000, 10000, 200000, false), sonnet55Price(), 92000],
    'claude subagent writes at the 5m rate' => fn () => [new PricedUsage('claude-code', 'claude-sonnet-5-5', 3000, 1000, 10000, 200000, true), sonnet55Price(), 77000],
    'codex input already contains cache read' => fn () => [new PricedUsage('codex', 'gpt-6-sol', 2000, 50000, 0, 40000, false), gpt6SolPrice(), 48000],
    'codex cache read above input clamps uncached to zero' => fn () => [new PricedUsage('codex', 'gpt-6-sol', 0, 100, 0, 500, false), gpt6SolPrice(), 100],
]);
