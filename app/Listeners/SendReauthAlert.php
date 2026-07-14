<?php

namespace App\Listeners;

use App\Events\AccountTokenRejected;
use App\Services\AccountReauthAlerter;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queued listener that turns an {@see AccountTokenRejected} event into a Slack
 * security alert. Thin by design — all payload and HTTP logic lives in
 * {@see AccountReauthAlerter}. Auto-discovered by the framework (no manual
 * registration), matching {@see AnnounceBossKill}.
 */
class SendReauthAlert implements ShouldQueue
{
    /**
     * Build the listener with the alerter it delegates to.
     *
     * @param  AccountReauthAlerter  $alerter  the Slack security alerter
     * @return void
     */
    public function __construct(private readonly AccountReauthAlerter $alerter) {}

    /**
     * Handle the event by delegating to the alerter.
     *
     * @param  AccountTokenRejected  $event  the token-rejection event
     * @return void
     */
    public function handle(AccountTokenRejected $event): void
    {
        $this->alerter->alert($event->account, $event->reason);
    }
}
