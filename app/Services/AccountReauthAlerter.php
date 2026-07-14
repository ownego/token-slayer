<?php

namespace App\Services;

use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Services\Recap\RecapPoster;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts a token-reject security alert to the dedicated Slack security channel
 * when an org account's OAuth refresh token is rejected by Anthropic. Mirrors
 * {@see RecapPoster}: a missing webhook URL is a silent,
 * logged skip rather than an error, and a failed POST is swallowed so the
 * queued listener does not retry-storm on a flaky webhook.
 *
 * Per the project token-hygiene rule, the alert payload carries the account
 * email, organization UUID, plan, reason, timestamp, and an admin deep-link
 * only — never any OAuth token material.
 */
class AccountReauthAlerter
{
    /**
     * Build and post the reauth alert for a rejected account.
     *
     * @param  Account  $account  the account whose refresh token was rejected
     * @param  string  $reason  the rejection reason (invalid_grant|unauthorized)
     * @return bool true when the alert was posted, false when skipped or failed
     */
    public function alert(Account $account, string $reason): bool
    {
        $url = config('services.slack_security.webhook_url');

        if (! $url) {
            Log::debug('reauth alert skipped: security webhook not configured', [
                'account_id' => $account->id,
            ]);

            return false;
        }

        try {
            Http::post($url, [
                'text' => "⚠️ Account needs re-auth — token rejected: {$account->email} ({$reason})",
                'blocks' => $this->buildBlocks($account, $reason),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('reauth alert failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Build the Slack Block Kit payload for the alert. Token-free by
     * construction — only identity, plan, reason, timestamp, and admin link.
     *
     * @param  Account  $account  the rejected account
     * @param  string  $reason  the rejection reason
     * @return array<int, array<string, mixed>> the Block Kit blocks
     */
    private function buildBlocks(Account $account, string $reason): array
    {
        return [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => '⚠️ Account needs re-auth — token rejected',
                    'emoji' => true,
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    ['type' => 'mrkdwn', 'text' => "*Account*\n{$account->email}"],
                    ['type' => 'mrkdwn', 'text' => "*Org*\n".($account->organization_uuid ?? '—')],
                    ['type' => 'mrkdwn', 'text' => "*Plan*\n".($account->plan ?? '—')],
                    ['type' => 'mrkdwn', 'text' => "*Reason*\n{$reason}"],
                    ['type' => 'mrkdwn', 'text' => "*Detected*\n".now()->toDayDateTimeString()],
                ],
            ],
            [
                'type' => 'context',
                'elements' => [
                    ['type' => 'mrkdwn', 'text' => "<{$this->adminUrl($account)}|Reconnect in admin →>"],
                ],
            ],
        ];
    }

    /**
     * Resolve the Filament admin edit URL for the account, naming the panel
     * explicitly so it resolves inside a queued job with no current-panel
     * context. Falls back to an app-URL-based path if Filament cannot build
     * the URL.
     *
     * @param  Account  $account  the account to link to
     * @return string the absolute admin edit URL
     */
    private function adminUrl(Account $account): string
    {
        try {
            return AccountResource::getUrl('edit', ['record' => $account->id], panel: 'admin');
        } catch (Throwable) {
            return rtrim(config('app.url'), '/')."/admin/accounts/{$account->id}/edit";
        }
    }
}
