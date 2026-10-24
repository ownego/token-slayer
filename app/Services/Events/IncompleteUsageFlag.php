<?php

namespace App\Services\Events;

use App\Enums\IncompleteUsageReason;
use App\Models\User;
use App\Services\Pricing\PricedUsage;

/**
 * Decides how a turn moves a member's incomplete-usage counter. Pure: the
 * caller merges the result into the user save ingest already performs, so
 * the counter costs no extra query.
 */
final class IncompleteUsageFlag
{
    /**
     * First hook version that sends input and cache tokens.
     *
     * @var int
     */
    public const int FULL_USAGE_MIN_HOOK_VERSION = 6;

    /**
     * The user attributes this turn changes, empty when it changes none.
     *
     * Only company turns on a provider whose hook can send full usage count:
     * a personal account's data is the member's own business. The increment
     * is read-then-write on the user loaded at authentication, so two
     * concurrent turns may count once; harmless, since only "above zero" is
     * ever shown.
     *
     * @param  User  $user  the member who sent the turn
     * @param  ?PricedUsage  $usage  the turn's token counts, null when the event carries no turn
     * @param  ?int  $accountId  the org account the turn is attributed to, null for a personal account
     * @param  ?string  $hookVersion  the version the hook reported on this request
     * @return array<string, mixed>
     */
    public function attributesFor(User $user, ?PricedUsage $usage, ?int $accountId, ?string $hookVersion): array
    {
        if ($usage === null || $accountId === null || ! $usage->isPricedProvider()) {
            return [];
        }

        $counter = (int) $user->incomplete_events;

        if ($usage->hasFullData()) {
            return $counter > 0 ? ['incomplete_events' => 0, 'incomplete_reason' => null] : [];
        }

        $sendsFullUsage = is_numeric($hookVersion) && (int) $hookVersion >= self::FULL_USAGE_MIN_HOOK_VERSION;

        return [
            'incomplete_events' => $counter + 1,
            'incomplete_reason' => $sendsFullUsage ? IncompleteUsageReason::ModifiedHook : IncompleteUsageReason::OldHook,
        ];
    }
}
