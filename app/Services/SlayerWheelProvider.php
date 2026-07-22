<?php

namespace App\Services;

use App\Services\GitHub\ReleaseAssetDownloader;
use App\Services\GitHub\ReleaseResolver;

class SlayerWheelProvider
{
    /**
     * Build the provider.
     *
     * @param  ReleaseResolver  $resolver  finds the current release's wheel asset
     * @param  ReleaseAssetDownloader  $downloader  fetches that asset's bytes
     * @return void
     */
    public function __construct(
        private readonly ReleaseResolver $resolver,
        private readonly ReleaseAssetDownloader $downloader,
    ) {}

    /**
     * The current slayer-cli wheel's bytes, or null if the release cannot be
     * resolved or the asset cannot be downloaded. Callers turn null into a
     * generic 503 — the distinction between the two failures is deliberately
     * not exposed, since either would reveal the state of the server's own
     * GitHub credential.
     *
     * @return string|null
     */
    public function bytes(): ?string
    {
        $release = $this->resolver->latest();

        if ($release === null) {
            return null;
        }

        return $this->downloader->download($release['asset_id']);
    }
}
