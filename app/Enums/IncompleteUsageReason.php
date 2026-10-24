<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Why a member's hook sends turns without model or input/cache tokens.
 * String-backed because the value persists on `users.incomplete_reason`.
 */
enum IncompleteUsageReason: string implements HasLabel
{
    /**
     * The hook predates the version that sends input and cache tokens.
     */
    case OldHook = 'old_hook';

    /**
     * The hook claims a version that sends them, yet the turn lacks them: an
     * edited hook or a client that is not our hook.
     */
    case ModifiedHook = 'modified_hook';

    /**
     * Tooltip text on the account card's dot.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::OldHook => 'Old hook, reinstall needed',
            self::ModifiedHook => 'Modified hook or unknown client',
        };
    }
}
