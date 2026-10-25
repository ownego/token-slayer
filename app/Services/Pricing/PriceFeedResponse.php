<?php

namespace App\Services\Pricing;

/**
 * One fetch of the price feed.
 */
final readonly class PriceFeedResponse
{
    /**
     * Hold the outcome of one fetch.
     *
     * @param  bool  $notModified  the feed answered 304 to our ETag
     * @param  array<array-key, mixed>  $entries  the decoded top-level object, empty when not modified
     * @param  ?string  $etag  the ETag the feed sent, null when absent or not modified
     * @param  ?string  $sha256  hash of the body, null when not modified
     */
    public function __construct(
        public bool $notModified,
        public array $entries,
        public ?string $etag,
        public ?string $sha256,
    ) {}
}
