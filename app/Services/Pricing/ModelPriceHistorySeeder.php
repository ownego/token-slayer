<?php

namespace App\Services\Pricing;

use App\Models\ModelPrice;

/**
 * Loads the dated price points that predate the first sync, so events from
 * before a price change are costed at the price of their own day. Idempotent:
 * the sync runs it every time, before any fill can price an event.
 */
final class ModelPriceHistorySeeder
{
    /**
     * Data file of dated prices, USD per MTok.
     *
     * @var string
     */
    private const string HISTORY_FILE = 'data/model_price_history.json';

    /**
     * Deepest nesting the history file uses (a list of flat objects).
     *
     * @var int
     */
    private const int HISTORY_MAX_DEPTH = 4;

    /**
     * Insert each history row absent by `(model, effective_from)`.
     *
     * @return int the number of rows inserted
     */
    public function seed(): int
    {
        $contents = file_get_contents(database_path(self::HISTORY_FILE));
        $rows = json_decode((string) $contents, true, self::HISTORY_MAX_DEPTH, JSON_THROW_ON_ERROR);
        $inserted = 0;

        foreach ($rows as $row) {
            $exists = ModelPrice::query()
                ->where('model', $row['model'])
                ->whereDate('effective_from', $row['effective_from'])
                ->exists();

            if ($exists) {
                continue;
            }

            ModelPrice::query()->create([
                'model' => $row['model'],
                'effective_from' => $row['effective_from'],
                'provider' => $row['provider'],
                'input_per_mtok' => $row['input'],
                'output_per_mtok' => $row['output'],
                'cache_read_per_mtok' => $row['cache_read'],
                'cache_write_5m_per_mtok' => $row['cache_write_5m'],
                'cache_write_1h_per_mtok' => $row['cache_write_1h'],
            ]);
            $inserted++;
        }

        return $inserted;
    }
}
