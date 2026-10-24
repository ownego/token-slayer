<?php

namespace App\Console\Commands;

use App\Exceptions\ModelPriceFeedException;
use App\Models\Event;
use App\Services\Pricing\MissingModelRecorder;
use App\Services\Pricing\ModelPriceSyncer;
use App\Services\Pricing\PricedUsage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Prices events stored before costs existed. It only queues every model of
 * the unpriced events as missing and runs the price sync, whose fill prices
 * each event at the price of its own day; it has no pricing loop of its own.
 * Run manually once per environment. No schedule entry.
 *
 * @deprecated temporary: remove once production is backfilled
 */
#[Signature('event-costs:backfill {--dry-run : List the models that would be queued and write nothing} {--force : Skip the confirmation}')]
#[Description('Queue the models of unpriced events and run the price sync to cost them')]
class BackfillEventCosts extends Command
{
    /**
     * Queue the models, sync, report.
     *
     * @param  MissingModelRecorder  $missingModels  queues a model for the fill
     * @param  ModelPriceSyncer  $syncer  the price sync that fills costs
     * @return int the command exit code
     */
    public function handle(MissingModelRecorder $missingModels, ModelPriceSyncer $syncer): int
    {
        $eventCounts = $this->unpricedEventCountsByModel();

        foreach ($eventCounts as $model => $count) {
            $this->line("{$model}: {$count} unpriced event(s)");
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $modelCount = count($eventCounts);

        if (! $this->option('force') && ! $this->confirm("Queue {$modelCount} model(s) and run the price sync?")) {
            $this->error('Aborted');

            return self::FAILURE;
        }

        foreach (array_keys($eventCounts) as $model) {
            $missingModels->record((string) $model);
        }

        try {
            $result = $syncer->sync();
        } catch (ModelPriceFeedException $exception) {
            report($exception);
            $this->error($exception->getMessage().' The models stay queued for the hourly sync.');

            return self::FAILURE;
        }

        $this->info("{$result->changedModels} price(s) changed, {$result->filledEvents} event(s) priced");

        return self::SUCCESS;
    }

    /**
     * Unpriced events of priced providers, counted per model. Incomplete
     * events are counted too (the count is "unpriced", not "will be priced"):
     * the fill skips them, and their model is a real one that gets priced and
     * dropped from the queue.
     *
     * @return array<string, int>
     */
    private function unpricedEventCountsByModel(): array
    {
        return Event::query()
            ->whereNull('cost_micro_usd')
            ->whereNotNull('model')
            ->whereIn('provider', PricedUsage::PRICED_PROVIDERS)
            ->groupBy('model')
            ->orderBy('model')
            ->selectRaw('model, COUNT(*) as event_count')
            ->pluck('event_count', 'model')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }
}
