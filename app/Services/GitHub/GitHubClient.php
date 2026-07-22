<?php

namespace App\Services\GitHub;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GitHubClient
{
    /**
     * Media type that makes GitHub return JSON release metadata.
     *
     * @var string
     */
    private const string ACCEPT_JSON = 'application/vnd.github+json';

    /**
     * Media type that makes GitHub return an asset's raw bytes, via a
     * short-lived signed redirect the HTTP client follows for us.
     *
     * @var string
     */
    private const string ACCEPT_BINARY = 'application/octet-stream';

    /**
     * A pre-authenticated request for JSON metadata endpoints, using the short
     * metadata timeout.
     *
     * @return PendingRequest
     */
    public function json(): PendingRequest
    {
        return $this->request(self::ACCEPT_JSON, (int) config('github.timeout'));
    }

    /**
     * A pre-authenticated request for asset downloads, using the longer
     * download timeout.
     *
     * @return PendingRequest
     */
    public function binary(): PendingRequest
    {
        return $this->request(self::ACCEPT_BINARY, (int) config('github.download_timeout'));
    }

    /**
     * Whether both the credential and the target repo are configured. Callers
     * check this to avoid a guaranteed-failing round trip on a fresh install.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return (string) config('github.token') !== ''
            && (string) config('github.cli_repo') !== '';
    }

    /**
     * The configured repo slug (e.g. 'ownego/token-slayer-cli') that release
     * endpoints are built from.
     *
     * @return string
     */
    public function repo(): string
    {
        return (string) config('github.cli_repo');
    }

    /**
     * Build the shared, authenticated request. This is the ONLY place the PAT
     * is read, so no other class can accidentally log or expose it.
     *
     * @param  string  $accept  media type driving GitHub's response format
     * @param  int  $timeout  seconds to wait before giving up
     * @return PendingRequest
     */
    private function request(string $accept, int $timeout): PendingRequest
    {
        return Http::baseUrl((string) config('github.api_url'))
            ->withHeaders([
                'Authorization' => 'Bearer '.config('github.token'),
                'Accept' => $accept,
                'X-GitHub-Api-Version' => (string) config('github.api_version'),
                'User-Agent' => (string) config('github.user_agent'),
            ])
            ->timeout($timeout);
    }
}
