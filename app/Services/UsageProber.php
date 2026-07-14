<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Events\AccountTokenRejected;
use App\Exceptions\UsageProbeException;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use Carbon\CarbonImmutable;

/**
 * Orchestrates a single account's quota probe cycle: refreshes the OAuth
 * access token when it is near expiry, calls Anthropic's usage API, and
 * records the result as an append-only {@see AccountUsageSnapshot}.
 *
 * Failures are classified per {@see UsageProbeException::$reason}: a dead
 * refresh token (invalid_grant/unauthorized) flips the account to
 * AccountStatus::NeedsReauth; transient failures (rate_limited/http_error/
 * connection_failed) leave status untouched so the next 5-minute cycle
 * retries. Per token-hygiene requirements, no raw token material is ever
 * written to `probe_error` or anywhere else.
 */
class UsageProber
{
    /**
     * Build the prober with the OAuth client it delegates all Anthropic HTTP
     * calls to.
     *
     * @param  AnthropicOAuthClient  $client  the token/usage API client
     * @return void
     */
    public function __construct(private readonly AnthropicOAuthClient $client) {}

    /**
     * Probe a single account: refresh its OAuth token if it is missing or
     * near expiry, then fetch and record a usage snapshot.
     *
     * @param  Account  $account  the org account to probe
     * @return AccountUsageSnapshot|null the recorded snapshot, or null when
     *                                   the account was skipped or the probe failed
     */
    public function probe(Account $account): ?AccountUsageSnapshot
    {
        if ($account->status === AccountStatus::Disabled || $account->oauth_refresh_token === null) {
            return null;
        }

        if ($this->needsRefresh($account) && ! $this->refreshToken($account)) {
            return null;
        }

        return $this->recordUsage($account);
    }

    /**
     * Determine whether the account's access token is missing an expiry or
     * within the refresh headroom of expiring.
     *
     * @param  Account  $account  the account to inspect
     * @return bool true when the token should be refreshed before use
     */
    private function needsRefresh(Account $account): bool
    {
        return $account->oauth_expires_at === null
            || $account->oauth_expires_at->lt(now()->addHours((int) config('token_slayer.probe.refresh_headroom_hours', 4)));
    }

    /**
     * Exchange the account's refresh token for a new access/refresh token
     * pair and persist it. On a dead-grant failure, flips the account to
     * AccountStatus::NeedsReauth; on a transient failure, leaves status
     * untouched so the next cycle retries.
     *
     * @param  Account  $account  the account whose token is being refreshed
     * @return bool true when the refresh succeeded and the account is ready to probe
     */
    private function refreshToken(Account $account): bool
    {
        try {
            $token = $this->client->refresh($account->oauth_refresh_token);
        } catch (UsageProbeException $exception) {
            // A rate-limit is transient and expected — skip silently and retry
            // next cycle, exactly as a 429 on the usage call is handled.
            if ($exception->reason === 'rate_limited') {
                return false;
            }

            if (in_array($exception->reason, ['invalid_grant', 'unauthorized'], true)) {
                // Capture the pre-mutation status so the alert fires only on the
                // true transition into NeedsReauth, never on a re-probe of an
                // already-flagged account (defensive — scopeProbeable already
                // excludes NeedsReauth accounts from the prober batch).
                $wasReauth = $account->getOriginal('status') === AccountStatus::NeedsReauth;

                $account->status = AccountStatus::NeedsReauth;
                $account->probe_error = "refresh failed: {$exception->reason}";
                $account->save();

                if (! $wasReauth) {
                    AccountTokenRejected::dispatch($account, $exception->reason);
                }

                return false;
            }

            // Transient failure: record the error, leave status untouched so the
            // next cycle retries.
            $account->probe_error = "refresh failed: {$exception->reason}";
            $account->save();

            return false;
        }

        $account->oauth_access_token = $token['access_token'];
        $account->oauth_refresh_token = $token['refresh_token'];
        $account->oauth_expires_at = now()->addSeconds($token['expires_in']);
        $account->save();

        return true;
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
            'util_7d_sonnet' => $this->roundedUtilization($usage, 'seven_day_sonnet'),
            'util_7d_oi' => $this->roundedUtilization($usage, 'seven_day_opus'),
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
