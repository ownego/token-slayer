<?php

namespace App\Enums;

use App\Services\AccountProfileSyncer;

/**
 * Outcome of syncing one account against Anthropic's profile API, returned by
 * {@see AccountProfileSyncer::sync()} so the calling command can
 * tally results without re-inspecting the account.
 */
enum AccountProfileSyncResult: string
{
    /**
     * Profile fetched and applied (plan/uuids updated, or an org-uuid
     * collision handled gracefully).
     */
    case Synced = 'synced';

    /**
     * The authorized account's email did not match the stored account email;
     * nothing was applied beyond recording a safe probe_error.
     */
    case Mismatched = 'mismatched';

    /**
     * The profile API call failed; a safe probe_error was recorded.
     */
    case Errored = 'errored';
}
