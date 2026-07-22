<?php

namespace App\Services\GitHub;

use App\Exceptions\ReleaseResolutionException;
use Throwable;

class ReleaseResolver
{
    /**
     * Stable alias the CLI release publishes alongside the versioned wheel;
     * preferred so the served filename never changes between releases.
     *
     * @var string
     */
    private const string PREFERRED_ASSET = 'slayer_cli-latest.whl';

    /**
     * Build the resolver.
     *
     * @param  GitHubClient  $client  authenticated transport for GitHub's API
     * @return void
     */
    public function __construct(private readonly GitHubClient $client) {}

    /**
     * Resolve the latest release's version and wheel asset id from the
     * configured (possibly private) CLI repo. Returns null on ANY failure so
     * callers fail soft: the wheel route turns null into a generic 503, the
     * profile page hides the version badge. Never throws; failures are
     * report()ed as ReleaseResolutionException so they remain diagnosable.
     *
     * @return array{version:string, asset_id:int, asset_name:string}|null
     */
    public function latest(): ?array
    {
        if (! $this->client->isConfigured()) {
            report(new ReleaseResolutionException('unconfigured', 'GitHub repo or credential is not configured'));

            return null;
        }

        $endpoint = "/repos/{$this->client->repo()}/releases/latest";

        try {
            $response = $this->client->json()->get($endpoint);
        } catch (Throwable $e) {
            report(new ReleaseResolutionException('transport', 'Could not reach GitHub', $e));

            return null;
        }

        if (! $response->successful()) {
            report(new ReleaseResolutionException('http_error', "GitHub returned {$response->status()}"));

            return null;
        }

        $tag = $response->json('tag_name');

        if (! is_string($tag) || trim($tag) === '') {
            report(new ReleaseResolutionException('no_release', 'Latest release has no tag_name'));

            return null;
        }

        $asset = $this->pickWheel($response->json('assets') ?? []);

        if ($asset === null) {
            report(new ReleaseResolutionException('no_asset', "Release {$tag} publishes no .whl asset"));

            return null;
        }

        return [
            'version' => ltrim(trim($tag), 'vV'),
            'asset_id' => (int) $asset['id'],
            'asset_name' => (string) $asset['name'],
        ];
    }

    /**
     * Pick the wheel asset from a release's asset list, preferring the stable
     * alias and otherwise taking any `.whl`.
     *
     * @param  array<int, array<string, mixed>>  $assets  raw `assets` array from the release payload
     * @return array<string, mixed>|null
     */
    private function pickWheel(array $assets): ?array
    {
        $wheels = array_values(array_filter(
            $assets,
            fn ($asset) => is_array($asset)
                && isset($asset['name'])
                && str_ends_with((string) $asset['name'], '.whl'),
        ));

        foreach ($wheels as $wheel) {
            if ($wheel['name'] === self::PREFERRED_ASSET) {
                return $wheel;
            }
        }

        return $wheels[0] ?? null;
    }
}
