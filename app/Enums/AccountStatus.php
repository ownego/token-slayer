<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Connection lifecycle of an org account. String-backed because the value
 * persists on `accounts.status`; implements Filament's label/color contracts
 * so selects and badge columns render it without ad-hoc mapping closures.
 */
enum AccountStatus: string implements HasColor, HasLabel
{
    /**
     * Account is connected and probeable.
     */
    case Active = 'active';

    /**
     * Refresh token died — admin must re-run the Connect flow.
     */
    case NeedsReauth = 'needs_reauth';

    /**
     * Soft-disabled by admin; prober skips it.
     */
    case Disabled = 'disabled';

    /**
     * Human-readable label shown by Filament selects and badges.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::NeedsReauth => 'Needs reauth',
            self::Disabled => 'Disabled',
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
            self::Active => 'success',
            self::NeedsReauth => 'danger',
            self::Disabled => 'gray',
        };
    }
}
