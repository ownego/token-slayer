<?php

namespace App\Services;

use App\Exceptions\UsageProbeException;
use App\Models\Account;
use Illuminate\Support\Facades\Log;

/**
 * Anchors an account's rolling 5-hour Anthropic usage window by sending one
 * minimal real inference at call time. Scheduled at fixed clock times so the
 * window boundaries land where the team wants them rather than drifting with
 * whenever the account first happens to be used.
 *
 * A 0-token usage/beacon call does NOT start a session, so this deliberately
 * spends a single token via {@see AnthropicOAuthClient::startSession}. Failures
 * are swallowed (returned as false) rather than retried: a late retry would
 * anchor the window at the wrong time, which is worse than not anchoring. A
 * swallowed failure is logged at warning level, because nothing else records
 * it — the scheduler discards the command's output.
 */
class SessionAnchorer
{
    /**
     * Build the anchorer with the OAuth client it sends the message through
     * and the shared refresher that guarantees a fresh access token first.
     *
     * @param  AnthropicOAuthClient  $client  the messages API client
     * @param  AccountTokenRefresher  $refresher  ensures a fresh access token
     * @return void
     */
    public function __construct(
        private readonly AnthropicOAuthClient $client,
        private readonly AccountTokenRefresher $refresher,
    ) {}

    /**
     * Anchor a single account's 5-hour window with one minimal message.
     *
     * @param  Account  $account  the account to anchor
     * @return bool true when the anchor message was sent; false when the
     *              account was skipped or the message failed
     */
    public function anchor(Account $account): bool
    {
        if (! $this->refresher->ensureFreshToken($account)) {
            return false;
        }

        try {
            $this->client->startSession($account->oauth_access_token);
        } catch (UsageProbeException $exception) {
            // Never retry at a wrong clock time — a missed anchor is preferable
            // to one that starts the window off-schedule. Dead-token handling
            // (NeedsReauth + alert) already happened in the refresher. This
            // warning is the only trace a missed anchor leaves: the scheduler
            // sends the command's own output to /dev/null.
            Log::warning('Session anchor failed', [
                'account_id' => $account->id,
                'reason' => $exception->reason,
                'detail' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}
