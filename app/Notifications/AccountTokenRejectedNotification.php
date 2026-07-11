<?php

namespace App\Notifications;

use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;
use Throwable;

/**
 * Slack security alert raised when an org account's OAuth refresh token is
 * rejected by Anthropic (invalid_grant/unauthorized). One outbound message
 * type = one Notification class (project convention); the shared transport is
 * Laravel's Slack notification channel, so adding another Slack message later
 * is a new Notification, not new transport code.
 *
 * Token-free by construction: the payload carries the account email,
 * organization UUID, plan, reason, timestamp, and an admin deep-link only —
 * never any OAuth token material.
 */
class AccountTokenRejectedNotification extends Notification
{
    /**
     * Build the notification for a rejected account.
     *
     * @param  Account  $account  the account whose refresh token was rejected
     * @param  string  $reason  the rejection reason (invalid_grant|unauthorized)
     * @return void
     */
    public function __construct(
        public readonly Account $account,
        public readonly string $reason,
    ) {}

    /**
     * The channels the notification is delivered on.
     *
     * @param  mixed  $notifiable  the ad-hoc Slack route it is sent to
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['slack'];
    }

    /**
     * Build the Slack Block Kit message — token-free, only identity, plan,
     * reason, timestamp, and the admin link.
     *
     * @param  mixed  $notifiable  the ad-hoc Slack route it is sent to
     * @return SlackMessage the Slack message payload
     */
    public function toSlack(mixed $notifiable): SlackMessage
    {
        $account = $this->account;
        $reason = $this->reason;

        return (new SlackMessage)
            ->text("⚠️ Account needs re-auth — token rejected: {$account->email} ({$reason})")
            ->headerBlock('⚠️ Account needs re-auth — token rejected')
            ->sectionBlock(function (SectionBlock $block) use ($account, $reason): void {
                $block->field("*Account*\n{$account->email}")->markdown();
                $block->field("*Org*\n".($account->organization_uuid ?? '—'))->markdown();
                $block->field("*Plan*\n".($account->plan ?? '—'))->markdown();
                $block->field("*Reason*\n{$reason}")->markdown();
                $block->field("*Detected*\n".now()->toDayDateTimeString())->markdown();
            })
            ->contextBlock(function (ContextBlock $block) use ($account): void {
                $block->text("<{$this->adminUrl($account)}|Reconnect in admin →>")->markdown();
            });
    }

    /**
     * Resolve the Filament admin edit URL for the account, naming the panel
     * explicitly so it resolves inside a queued job with no current-panel
     * context. Falls back to an app-URL-based path if Filament cannot build it.
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
