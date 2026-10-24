<?php

namespace App\Console\Commands;

use App\Exceptions\ModelPriceFeedException;
use App\Models\MissingModel;
use App\Services\Pricing\ModelPriceSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs the model price sync. Daily it always runs; hourly (`--if-missing`)
 * it runs only while a model went missing recently, so a newly used model
 * gets priced soon without fetching the feed every hour forever.
 */
#[Signature('model-prices:sync {--if-missing : Run only while a model first seen within the hourly window has no price}')]
#[Description('Update model list prices from the LiteLLM feed and price events of models that had none')]
class SyncModelPrices extends Command
{
    /**
     * Sync prices, or skip when the hourly run has nothing recent to chase.
     *
     * @param  ModelPriceSyncer  $syncer  the price sync
     * @return int the command exit code
     */
    public function handle(ModelPriceSyncer $syncer): int
    {
        if ($this->option('if-missing') && ! $this->hasRecentlyMissingModel()) {
            $this->info('No missing models');

            return self::SUCCESS;
        }

        try {
            $result = $syncer->sync();
        } catch (ModelPriceFeedException $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $status = $result->notModified ? 'feed unchanged' : "{$result->changedModels} price(s) changed";
        $this->info("{$status}, {$result->filledEvents} event(s) priced");

        return self::SUCCESS;
    }

    /**
     * Whether a missing model was first seen within the hourly window. Older
     * ones are left to the daily run.
     *
     * @return bool
     */
    private function hasRecentlyMissingModel(): bool
    {
        $windowHours = config('token_slayer.model_prices.hourly_window_hours');

        return MissingModel::query()
            ->where('first_seen_at', '>=', now()->subHours($windowHours))
            ->exists();
    }
}
