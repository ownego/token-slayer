<?php

namespace App\Services\GitHub;

use App\Exceptions\ReleaseResolutionException;
use Throwable;

class ReleaseAssetDownloader
{
    /**
     * Build the downloader.
     *
     * @param  GitHubClient  $client  authenticated transport for GitHub's API
     * @return void
     */
    public function __construct(private readonly GitHubClient $client) {}

    /**
     * Download a release asset's raw bytes. The client asks for
     * `application/octet-stream`, which GitHub answers with a short-lived
     * signed redirect that the HTTP client follows server-side — so the signed
     * URL is never handed to the caller. Buffered rather than streamed: the
     * wheel is ~140 KB, matching AvatarProxyController.
     *
     * @param  int  $assetId  GitHub release asset id from ReleaseResolver
     * @return string|null raw bytes, or null on any failure
     */
    public function download(int $assetId): ?string
    {
        $endpoint = "/repos/{$this->client->repo()}/releases/assets/{$assetId}";

        try {
            $response = $this->client->binary()->get($endpoint);
        } catch (Throwable $e) {
            report(new ReleaseResolutionException('transport', 'Could not download the release asset', $e));

            return null;
        }

        if (! $response->successful()) {
            report(new ReleaseResolutionException('http_error', "Asset download returned {$response->status()}"));

            return null;
        }

        return $response->body();
    }
}
