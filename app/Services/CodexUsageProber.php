<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Services\Contracts\UsageProberContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Fetches a Codex account's current quota usage and records it as an
 * append-only {@see AccountUsageSnapshot} — the Codex-side counterpart to
 * {@see UsageProber}. Hits `GET /backend-api/wham/usage` impersonating the
 * "Codex Desktop" client (live-verified 2026-09-04 against the real
 * endpoint, headers reverse-engineered from a third-party app's source —
 * see the token-slayer KB's "Codex usage/rate-limits" section) — a
 * different, working path from the real `codex` CLI's own WebSocket-based
 * mechanism. No token refresh step: unlike Claude's ~4h access tokens,
 * Codex access tokens live ~10 days (see the
 * codex-oauth-server-side-provisioning research note), so a stale token
 * here surfaces as a probe failure recorded in `probe_error`, not a
 * silent skip — a future Codex token-refresh command is out of scope for
 * this parity work.
 */
class CodexUsageProber implements UsageProberContract
{
    /**
     * @var string
     */
    private const string USAGE_URL = 'https://chatgpt.com/backend-api/wham/usage';

    /**
     * The window duration (minutes) Codex's 5h-equivalent quota bucket
     * reports as, used to classify which of the response's two windows is
     * the 5h-equivalent one.
     *
     * @var int
     */
    private const int SESSION_WINDOW_MINUTES = 300;

    /**
     * The window duration (minutes) Codex's 7d-equivalent quota bucket
     * reports as.
     *
     * @var int
     */
    private const int WEEKLY_WINDOW_MINUTES = 10080;

    /**
     * Tolerance (minutes) for matching a window's reported duration against
     * the two known bucket lengths above, absorbing minor drift without
     * matching unrelated durations (e.g. a free-tier account's 30-day cap).
     *
     * @var int
     */
    private const int WINDOW_DURATION_TOLERANCE_MINUTES = 1;

    /**
     * Probe a single Codex account: fetch and record a usage snapshot using
     * its currently stored access token.
     *
     * @param  Account  $account  the org account to probe (must have provider = 'codex')
     * @return AccountUsageSnapshot|null the recorded snapshot, or null when the probe failed
     */
    public function probe(Account $account): ?AccountUsageSnapshot
    {
        $credential = $account->codexCredential;
        if ($credential === null || $credential->codex_access_token === null) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'codex-cli',
                'OpenAI-Beta' => 'codex-1',
                'originator' => 'Codex Desktop',
                'ChatGPT-Account-Id' => $credential->chatgpt_account_id,
            ])
                ->withToken($credential->codex_access_token)
                ->get(self::USAGE_URL)
                ->throw();
        } catch (ConnectionException) {
            $credential->probe_error = 'usage probe failed: connection error';
            $credential->save();

            return null;
        } catch (RequestException $exception) {
            if ($exception->response->status() === 429) {
                return null;
            }

            $credential->probe_error = "usage probe failed: HTTP {$exception->response->status()}";
            $credential->save();

            return null;
        }

        $usage = $response->json();
        [$session, $weekly] = $this->classifyWindows(
            $usage['rate_limit']['primary_window'] ?? null,
            $usage['rate_limit']['secondary_window'] ?? null,
        );

        $snapshot = $account->usageSnapshots()->create([
            'util_5h' => $this->roundedUtilization($session),
            'util_7d' => $this->roundedUtilization($weekly),
            'reset_5h_at' => $this->parseResetsAt($session),
            'reset_7d_at' => $this->parseResetsAt($weekly),
            'raw' => $usage,
            'created_at' => now(),
        ]);

        $credential->last_probed_at = now();
        $credential->probe_error = null;
        $credential->save();

        return $snapshot;
    }

    /**
     * Classify the response's `primary_window`/`secondary_window` into the
     * 5h-equivalent ("session") and 7d-equivalent ("weekly") slots by their
     * reported duration, not by fixed position — mirrors the classification
     * a third-party Codex multi-account app uses, since Codex's own API
     * doesn't label which window is which. A window whose duration matches
     * neither known length (e.g. a free-tier account's 30-day cap) falls
     * back to `primary → session`, `secondary → weekly`, so the caller
     * still gets a number rather than nothing.
     *
     * @param  ?array<string, mixed>  $primary  the response's primary_window, if present
     * @param  ?array<string, mixed>  $secondary  the response's secondary_window, if present
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>} the session and weekly windows
     */
    private function classifyWindows(?array $primary, ?array $secondary): array
    {
        $session = null;
        $weekly = null;

        foreach (['primary' => $primary, 'secondary' => $secondary] as $window) {
            if ($window === null || ! isset($window['used_percent'])) {
                continue;
            }

            $kind = $this->classifyDuration($window);
            if ($kind === 'session' && $session === null) {
                $session = $window;
            } elseif ($kind === 'weekly' && $weekly === null) {
                $weekly = $window;
            }
        }

        // A window whose duration matches neither known shape is deliberately
        // filed under neither. It used to fall back into the session slot,
        // which put a free-tier account's 30-day cap behind a "5H" label with
        // nothing on screen to say otherwise. `raw` keeps every window, and
        // {@see \App\Services\Accounts\CodexUsageWindows} reads them back
        // with the duration each one actually reports.

        return [$session, $weekly];
    }

    /**
     * Classify one window's `limit_window_seconds` into 'session', 'weekly',
     * or null (unrecognized duration).
     *
     * @param  array<string, mixed>  $window  a primary_window or secondary_window entry
     * @return 'session'|'weekly'|null
     */
    private function classifyDuration(array $window): ?string
    {
        $seconds = $window['limit_window_seconds'] ?? null;
        if (! is_int($seconds) && ! is_float($seconds)) {
            return null;
        }
        $minutes = $seconds / 60;

        if (abs($minutes - self::SESSION_WINDOW_MINUTES) <= self::WINDOW_DURATION_TOLERANCE_MINUTES) {
            return 'session';
        }
        if (abs($minutes - self::WEEKLY_WINDOW_MINUTES) <= self::WINDOW_DURATION_TOLERANCE_MINUTES) {
            return 'weekly';
        }

        return null;
    }

    /**
     * Read a classified window's `used_percent` and round it to the nearest
     * integer for storage.
     *
     * @param  ?array<string, mixed>  $window  the classified session or weekly window, or null when absent
     * @return int|null the rounded percent, or null when the window is absent
     */
    private function roundedUtilization(?array $window): ?int
    {
        $utilization = $window['used_percent'] ?? null;

        return $utilization === null ? null : (int) round($utilization);
    }

    /**
     * Parse a classified window's `reset_at` (Unix timestamp).
     *
     * @param  ?array<string, mixed>  $window  the classified session or weekly window, or null when absent
     * @return Carbon|null the parsed reset time, or null when the window is absent
     */
    private function parseResetsAt(?array $window): ?Carbon
    {
        $resetAt = $window['reset_at'] ?? null;

        return $resetAt === null ? null : Carbon::createFromTimestamp($resetAt);
    }
}
