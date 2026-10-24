<?php

namespace App\Services\Pricing;

use App\Models\ModelPrice;

/**
 * Prices one turn at list price. Pure: the caller fetches the price row.
 */
final class EventCostCalculator
{
    /**
     * The turn's cost in micro-dollars (tokens times USD per MTok).
     *
     * Codex reports cached input inside `input_tokens`, so only the remainder
     * is billed at the input rate; a client reporting more cache reads than
     * input is clamped to zero uncached input. Claude Code writes the main
     * thread's cache with a 1h TTL and a subagent's with a 5m TTL; a model
     * with no 1h price falls back to the 5m rate.
     *
     * @param  PricedUsage  $usage  the turn's token counts
     * @param  ModelPrice  $price  the price in force on the turn's day
     * @return int
     */
    public function costMicroUsd(PricedUsage $usage, ModelPrice $price): int
    {
        if ($usage->provider === 'codex') {
            $uncachedInput = max(0, $usage->inputTokens - $usage->cacheReadTokens);

            $cost = $uncachedInput * $price->input_per_mtok
                + $usage->cacheReadTokens * $price->cache_read_per_mtok
                + $usage->outputTokens * $price->output_per_mtok;

            return (int) round($cost);
        }

        $writeRate = $usage->isSubagent
            ? $price->cache_write_5m_per_mtok
            : ($price->cache_write_1h_per_mtok ?? $price->cache_write_5m_per_mtok);

        $cost = $usage->inputTokens * $price->input_per_mtok
            + $usage->outputTokens * $price->output_per_mtok
            + $usage->cacheReadTokens * $price->cache_read_per_mtok
            + $usage->cacheWriteTokens * $writeRate;

        return (int) round($cost);
    }
}
