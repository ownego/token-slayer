<?php

namespace App\Listeners;

use App\Events\AccountTokenRejected;
use App\Notifications\AccountTokenRejectedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Slack\SlackRoute;
use Illuminate\Support\Facades\Notification;

/**
 * Queued listener that turns an {@see AccountTokenRejected} event into a Slack
 * security alert, sent through Laravel's Slack notification channel to the
 * dedicated security bot token + channel. Thin by design — the message shape
 * lives in {@see AccountTokenRejectedNotification} and the transport in the
 * framework channel. Auto-discovered (no manual registration).
 *
 * A missing bot token or channel is a silent skip (the feature is simply not
 * configured in that environment), consistent with the project's no-logging-on-
 * production-paths rule.
 */
class SendReauthAlert implements ShouldQueue
{
    /**
     * Handle the event by dispatching the Slack notification to the security
     * channel, using the dedicated security bot token for that route.
     *
     * @param  AccountTokenRejected  $event  the token-rejection event
     * @return void
     */
    public function handle(AccountTokenRejected $event): void
    {
        $token = config('services.slack_security.bot_token');
        $channel = config('services.slack_security.channel');

        if (! $token || ! $channel) {
            return;
        }

        Notification::route('slack', SlackRoute::make($channel, $token))
            ->notify(new AccountTokenRejectedNotification($event->account, $event->reason));
    }
}
