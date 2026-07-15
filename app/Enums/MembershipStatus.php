<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether a developer's membership on an org account is actively tracked.
 * String-backed because the value persists on `account_user.status`; implements
 * Filament's label/color contracts so badges render without ad-hoc closures.
 */
enum MembershipStatus: string implements HasColor, HasLabel
{
    /**
     * Materialized automatically from an event; not yet promoted by an admin.
     */
    case Untracked = 'untracked';

    /**
     * Promoted by an admin — a tracked member of the account.
     */
    case Tracked = 'tracked';

    /**
     * Human-readable label shown by Filament badges.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Untracked => 'Untracked',
            self::Tracked => 'Tracked',
        };
    }

    /**
     * Badge color used by Filament table columns.
     *
     * @return string
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Untracked => 'gray',
            self::Tracked => 'success',
        };
    }
}
