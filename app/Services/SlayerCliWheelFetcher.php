<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Fetches the slayer-cli wheel from its PRIVATE GitHub release, server-side,
 * so an anonymous install script never needs GitHub credentials of its own.
 * The repo-scoped token lives only in this app's config; the fetched bytes
 * are never persisted, only handed back to the caller for one response.
 */
final class SlayerCliWheelFetcher
{
    /**
     * Fetch the configured wheel asset's raw bytes from the latest GitHub
     * release. Every failure mode (missing config, release lookup failure,
     * no matching asset, asset download failure) is tolerant — returns null
     * rather than throwing, so the caller can 404 cleanly.
     *
     * @return ?string the wheel's raw bytes, or null when unavailable
     */
    public function fetch(): ?string
    {
        $repo = config('token_slayer.slayer_cli.github_repo');
        $token = config('token_slayer.slayer_cli.github_token');

        if (blank($repo) || blank($token)) {
            return null;
        }

        $release = Http::withToken($token)
            ->acceptJson()
            ->get("https://api.github.com/repos/{$repo}/releases/latest");

        if ($release->failed()) {
            return null;
        }

        $assetUrl = $this->findAssetUrl($release->json('assets', []));

        if ($assetUrl === null) {
            return null;
        }

        $asset = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/octet-stream'])
            ->get($assetUrl);

        return $asset->successful() ? $asset->body() : null;
    }

    /**
     * Find the API URL of the release asset matching the configured wheel
     * name (not `browser_download_url`, which requires a web session on a
     * private repo — the API asset URL works with a bearer token instead).
     *
     * @param  array<int, array<string, mixed>>  $assets  the release's assets array
     * @return ?string the matching asset's API url, or null when not found
     */
    private function findAssetUrl(array $assets): ?string
    {
        $name = config('token_slayer.slayer_cli.wheel_asset_name');

        foreach ($assets as $asset) {
            if (($asset['name'] ?? null) === $name) {
                return $asset['url'] ?? null;
            }
        }

        return null;
    }
}
