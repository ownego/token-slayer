<?php

namespace App\Services\Pricing;

/**
 * What one price sync did.
 */
final readonly class PriceSyncResult
{
    /**
     * Hold the outcome of one sync.
     *
     * @param  bool  $notModified  the feed answered 304, so no price was read
     * @param  int  $changedModels  models that got a new price row
     * @param  int  $filledEvents  stored events the missing-model fill priced
     */
    public function __construct(
        public bool $notModified,
        public int $changedModels,
        public int $filledEvents,
    ) {}
}
