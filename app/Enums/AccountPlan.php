<?php

namespace App\Enums;

use App\Services\Accounts\PlanResolver;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Normalized Anthropic subscription plan of an org account. String-backed
 * because the value persists on `accounts.plan`; implements Filament's
 * label/color contracts so selects and badge columns render it without ad-hoc
 * mapping closures. Derived from the profile's `organization_type` ×
 * `rate_limit_tier` pair by {@see PlanResolver}.
 */
enum AccountPlan: string implements HasColor, HasLabel
{
    /**
     * Free tier.
     */
    case Free = 'free';

    /**
     * Claude Pro.
     */
    case Pro = 'pro';

    /**
     * Claude Max 5x.
     */
    case Max5x = 'max_5x';

    /**
     * Claude Max 20x.
     */
    case Max20x = 'max_20x';

    /**
     * Claude Max whose tier could not be narrowed to 5x or 20x.
     */
    case Max = 'max';

    /**
     * An unrecognized or not-yet-synced plan (raw org_type/tier retained on
     * the row for diagnosis).
     */
    case Unknown = 'unknown';

    /**
     * Human-readable label shown by Filament selects and badges.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Pro => 'Pro',
            self::Max5x => 'Max 5x',
            self::Max20x => 'Max 20x',
            self::Max => 'Max',
            self::Unknown => 'Unknown',
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
            self::Free => 'gray',
            self::Pro => 'info',
            self::Max5x => 'warning',
            self::Max20x, self::Max => 'success',
            self::Unknown => 'gray',
        };
    }
}
