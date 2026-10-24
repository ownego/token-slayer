<?php

namespace Database\Factories;

use App\Models\ModelPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModelPrice>
 */
class ModelPriceFactory extends Factory
{
    /**
     * A Claude Sonnet 5.5 list price in force since the start of September 2026.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'model' => 'claude-sonnet-5-5',
            'provider' => 'anthropic',
            'effective_from' => '2026-09-01',
            'input_per_mtok' => 2,
            'output_per_mtok' => 10,
            'cache_read_per_mtok' => 0.10,
            'cache_write_5m_per_mtok' => 2.5,
            'cache_write_1h_per_mtok' => 4,
        ];
    }

    /**
     * A Codex gpt-6-sol list price; OpenAI bills no cache writes.
     *
     * @return static
     */
    public function codex(): static
    {
        return $this->state(fn (): array => [
            'model' => 'gpt-6-sol',
            'provider' => 'openai',
            'input_per_mtok' => 2,
            'output_per_mtok' => 10,
            'cache_read_per_mtok' => 0.20,
            'cache_write_5m_per_mtok' => 0,
            'cache_write_1h_per_mtok' => null,
        ]);
    }
}
