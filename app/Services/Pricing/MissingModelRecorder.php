<?php

namespace App\Services\Pricing;

use App\Models\MissingModel;

/**
 * Notes that a model was seen on a complete event without a price.
 */
final class MissingModelRecorder
{
    /**
     * Insert the model or move its `last_seen_at` to now. The fill keeps any
     * row touched after it started, so callers record AFTER the event row is
     * written: a row touched before the insert could be deleted by a fill
     * that never saw the event.
     *
     * @param  string  $model  the raw model id from the event
     * @return void
     */
    public function record(string $model): void
    {
        $now = now();

        MissingModel::query()->upsert(
            [['model' => $model, 'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now]],
            ['model'],
            ['last_seen_at', 'updated_at'],
        );
    }
}
