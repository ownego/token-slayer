<?php

namespace App\Events;

use App\Listeners\SendReauthAlert;
use App\Models\Account;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the quota prober detects that an org account's OAuth refresh
 * token has been rejected by Anthropic (invalid_grant/unauthorized) and the
 * account has just transitioned to NeedsReauth. Consumed by
 * {@see SendReauthAlert} to raise a Slack security alert.
 *
 * Not broadcast — this is a server-internal signal only.
 */
class AccountTokenRejected
{
    use Dispatchable, SerializesModels;

    /**
     * @param  Account  $account  the account whose refresh token was rejected
     * @param  string  $reason  the rejection reason (invalid_grant|unauthorized)
     * @return void
     */
    public function __construct(
        public readonly Account $account,
        public readonly string $reason,
    ) {}
}
