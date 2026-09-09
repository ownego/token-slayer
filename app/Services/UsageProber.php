<?php

namespace App\Services;

use App\Exceptions\UsageProbeException;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Services\Contracts\UsageProberContract;
use Carbon\CarbonImmutable;

/**
 * Orchestrates a single account's quota probe cycle: ensures a fresh OAuth
 * access token via {@see AccountTokenRefresher}, calls Anthropic's usage API,
 * and records the result as an append-only {@see AccountUsageSnapshot}.
 *
 * A usage-call failure is classified per {@see UsageProbeException::$reason}:
 * a 429 is treated as expected/transient and fails silently; any other failure
 * records a safe `probe_error` and leaves the account status untouched so the
 * next 5-minute cycle retries. Dead-token handling lives in the refresher. Per
 * token-hygiene requirements, no raw token material is ever written to
 * `probe_error` or anywhere else.
 */
class UsageProber implements UsageProberContract
{
    /**
     * Build the prober with the OAuth client it fetches usage from and the
     * shared refresher that guarantees a fresh access token first.
     *
     * @param  AnthropicOAuthClient  $client  the usage API client
     * @param  AccountTokenRefresher  $refresher  ensures a fresh access token
     * @return void
     */
    public function __construct(
        private readonly AnthropicOAuthClient $client,
        private readonly AccountTokenRefresher $refresher,
    ) {}

    /**
     * Probe a single account: ensure a fresh OAuth token, then fetch and
     * record a usage snapshot.
     *
     * @param  Account  $account  the org account to probe
     * @return AccountUsageSnapshot|null the recorded snapshot, or null when
     *                                   the account was skipped or the probe failed
     */
    public function probe(Account $account): ?AccountUsageSnapshot
    {
        if (! $this->refresher->ensureFreshToken($account)) {
            return null;
        }

        return $this->recordUsage($account);
    }

    /**
     * Fetch the account's current usage from Anthropic and persist it as a
     * new AccountUsageSnapshot. A 429 is treated as expected/transient and
     * fails silently (no probe_error); any other failure records a safe
     * probe_error and leaves the account status untouched.
     *
     * @param  Account  $account  the account to fetch usage for
     * @return AccountUsageSnapshot|null the recorded snapshot, or null on failure
     */
    private function recordUsage(Account $account): ?AccountUsageSnapshot
    {
        try {
            $usage = $this->client->usage($account->oauth_access_token);
        } catch (UsageProbeException $exception) {
            if ($exception->reason !== 'rate_limited') {
                $account->probe_error = "usage probe failed: {$exception->reason}";
                $account->save();
            }

            return null;
        }

        $snapshot = $account->usageSnapshots()->create([
            'util_5h' => $this->roundedUtilization($usage, 'five_hour'),
            'util_7d' => $this->roundedUtilization($usage, 'seven_day'),
            'reset_5h_at' => $this->parseResetsAt($usage, 'five_hour'),
            'reset_7d_at' => $this->parseResetsAt($usage, 'seven_day'),
            'raw' => $usage,
            'created_at' => now(),
        ]);

        $account->last_probed_at = now();
        $account->probe_error = null;
        $account->save();

        return $snapshot;
    }

    /**
     * Read a bucket's `utilization` percent and round it to the nearest
     * integer for storage. The API already returns a 0-100 percent value —
     * it must not be multiplied by 100.
     *
     * @param  array<string, mixed>  $usage  the decoded usage response
     * @param  string  $bucket  the top-level bucket key, e.g. 'five_hour'
     * @return int|null the rounded percent, or null when the bucket is absent
     */
    private function roundedUtilization(array $usage, string $bucket): ?int
    {
        $utilization = $usage[$bucket]['utilization'] ?? null;

        return $utilization === null ? null : (int) round($utilization);
    }

    /**
     * Parse a bucket's ISO-8601 `resets_at` timestamp.
     *
     * @param  array<string, mixed>  $usage  the decoded usage response
     * @param  string  $bucket  the top-level bucket key, e.g. 'five_hour'
     * @return CarbonImmutable|null the parsed reset time, or null when the bucket is absent
     */
    private function parseResetsAt(array $usage, string $bucket): ?CarbonImmutable
    {
        $resetsAt = $usage[$bucket]['resets_at'] ?? null;

        return $resetsAt === null ? null : CarbonImmutable::parse($resetsAt);
    }
}
